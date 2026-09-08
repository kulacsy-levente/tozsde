<?php
/**
 * Egyetlen JSON belépési pont: api.php?action=...
 *
 * Minden állapotváltoztató művelet POST + CSRF-token. Az olvasások GET-tel
 * mennek. A válasz mindig { ok: true, data: ... } vagy { ok: false, error: ... }.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/server/bootstrap.php';

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Service\AuthService;
use App\Service\Installer;
use App\Service\MarketService;
use App\Service\NewsService;
use App\Service\PortfolioService;
use App\Service\PriceEngine;
use App\Service\SimulationService;
use App\Service\TradeService;

$action = Request::action();

try {
    // --- Telepítettség ---
    if (!Installer::isInstalled()) {
        if ($action === 'me') {
            Response::ok(['installed' => false]);
        }
        Response::notInstalled();
    }

    Session::start();

    // --- CSRF minden író művelethez ---
    $writeActions = [
        'register', 'login', 'logout', 'account_create', 'account_switch',
        'trade', 'close', 'simulate', 'reset',
    ];
    if (in_array($action, $writeActions, true)) {
        if (!Request::isPost()) {
            Response::fail('Ehhez a művelethez POST kérés kell.', 405);
        }
        // A belépés és a regisztráció is tokenhez kötött: a tokent az
        // index.php adja ki, tehát a kérésnek az oldalról kell indulnia.
        if (!Session::checkCsrf()) {
            Response::fail('Lejárt vagy hiányzó biztonsági token. Töltsd újra az oldalt.', 419, 'csrf');
        }
    }

    // --- Bejelentkezést igénylő műveletek ---
    $authActions = [
        'account_create', 'account_switch', 'accounts',
        'portfolio', 'trade', 'close', 'simulate', 'history', 'reset',
    ];
    if (in_array($action, $authActions, true) && Session::userId() === null) {
        Response::unauthorized();
    }

    switch ($action) {

        // =============================================================
        //  Munkamenet
        // =============================================================

        case 'me': {
            $userId = Session::userId();
            $data = [
                'installed' => true,
                'csrf'      => Session::csrfToken(),
                'config'    => [
                    'default_capital' => (float) config('default_capital'),
                    'min_capital'     => (float) config('min_capital'),
                    'max_capital'     => (float) config('max_capital'),
                    'max_leverage'    => (float) config('max_leverage'),
                    'horizons'        => array_keys(config('horizons')),
                    'retention_days'  => (int) config('minute_retention_days'),
                    'vendor_local'    => Db::setting('vendor_local', '0') === '1',
                ],
            ];

            if ($userId !== null) {
                $user = Db::one('SELECT id, username FROM users WHERE id = ?', [$userId]);
                $data['user']     = $user;
                $data['accounts'] = AuthService::accounts($userId);
                $data['account_id'] = Session::accountId();
            }

            Response::ok($data);
        }

        case 'register': {
            $result = AuthService::register(
                Request::str('username'),
                Request::str('password'),
                Request::float('capital', (float) config('default_capital')),
                Request::str('account_name')
            );
            Response::ok($result);
        }

        case 'login': {
            $result = AuthService::login(Request::str('username'), Request::str('password'));
            Response::ok($result);
        }

        case 'logout': {
            Session::logout();
            Response::ok(['logged_out' => true]);
        }

        case 'accounts': {
            Response::ok([
                'accounts'   => AuthService::accounts((int) Session::userId()),
                'account_id' => Session::accountId(),
            ]);
        }

        case 'account_create': {
            $id = AuthService::createAccount(
                (int) Session::userId(),
                Request::str('name'),
                Request::float('capital', (float) config('default_capital'))
            );
            AuthService::switchAccount($id);
            Response::ok(['account_id' => $id]);
        }

        case 'account_switch': {
            AuthService::switchAccount(Request::int('account_id'));
            Response::ok(['account_id' => Session::accountId()]);
        }

        // =============================================================
        //  Piac
        // =============================================================

        case 'assets': {
            PriceEngine::ensureDaily();
            Response::ok(['assets' => MarketService::assets()]);
        }

        case 'tick': {
            $tick = MarketService::tick(Request::str('symbol'));

            // A bejelentkezett fiók pozícióit minden ticknél megnézzük:
            // a likvidálás nem várhat a következő oldalfrissítésig.
            if (Session::userId() !== null) {
                $accountId = AuthService::currentAccountId();
                $liquidated = TradeService::checkLiquidations($accountId);

                $tick['portfolio']   = PortfolioService::snapshot($accountId, $tick['prices']);
                $tick['liquidated']  = $liquidated;

                PortfolioService::recordEquity($accountId, $tick['portfolio']['equity']);
            }

            Response::ok($tick);
        }

        case 'candles': {
            PriceEngine::ensureDaily();
            Response::ok(MarketService::candles(
                Request::str('symbol', 'BTC'),
                Request::str('tf', '1m') === '1d' ? '1d' : '1m',
                Request::int('limit', 1500)
            ));
        }

        case 'news': {
            $symbol  = Request::str('symbol');
            $assetId = null;
            if ($symbol !== '') {
                $asset   = PriceEngine::assetBySymbol($symbol);
                $assetId = $asset ? (int) $asset['id'] : null;
            }
            Response::ok(['news' => NewsService::recent(PriceEngine::simNow(), Request::int('limit', 60), $assetId)]);
        }

        // =============================================================
        //  Kereskedés
        // =============================================================

        case 'portfolio': {
            PriceEngine::ensureDaily();
            $accountId = AuthService::currentAccountId();
            TradeService::checkLiquidations($accountId);

            Response::ok([
                'portfolio' => PortfolioService::snapshot($accountId),
                'stats'     => PortfolioService::stats($accountId),
                'equity'    => PortfolioService::equityCurve($accountId),
            ]);
        }

        case 'trade': {
            PriceEngine::ensureDaily();
            $accountId = AuthService::currentAccountId();

            $qty    = Request::float('qty', 0.0);
            $amount = Request::float('amount', 0.0);

            $result = TradeService::open(
                $accountId,
                Request::str('symbol'),
                Request::str('side', 'long'),
                $qty > 0 ? $qty : null,
                $amount > 0 ? $amount : null,
                Request::float('leverage', 1.0)
            );

            Response::ok([
                'trade'     => $result,
                'portfolio' => PortfolioService::snapshot($accountId),
            ]);
        }

        case 'close': {
            PriceEngine::ensureDaily();
            $accountId = AuthService::currentAccountId();

            $qty = Request::float('qty', 0.0);
            $result = TradeService::close($accountId, Request::int('position_id'), $qty > 0 ? $qty : null);

            Response::ok([
                'trade'     => $result,
                'portfolio' => PortfolioService::snapshot($accountId),
            ]);
        }

        case 'history': {
            $accountId = AuthService::currentAccountId();
            Response::ok([
                'history' => PortfolioService::history($accountId, Request::int('limit', 200)),
                'stats'   => PortfolioService::stats($accountId),
            ]);
        }

        // =============================================================
        //  Szimuláció és visszaállítás
        // =============================================================

        case 'simulate': {
            $accountId = AuthService::currentAccountId();
            $result    = SimulationService::fastForward($accountId, Request::str('horizon', '1d'));

            Response::ok([
                'simulation' => $result,
                'portfolio'  => PortfolioService::snapshot($accountId),
            ]);
        }

        case 'reset': {
            $scope     = Request::str('scope', 'account');
            $accountId = AuthService::currentAccountId();

            switch ($scope) {
                case 'account':
                    Installer::resetAccount($accountId);
                    break;

                case 'market':
                    Installer::resetWorld(false);
                    break;

                case 'world':
                    Installer::resetWorld(true);
                    Installer::resetAccount($accountId);
                    break;

                default:
                    Response::fail('Ismeretlen visszaállítási mód: ' . $scope);
            }

            Response::ok([
                'scope'     => $scope,
                'portfolio' => PortfolioService::snapshot($accountId),
            ]);
        }

        case '':
            Response::fail('Hiányzik az action paraméter.', 400);

        default:
            Response::fail('Ismeretlen művelet: ' . $action, 404);
    }
} catch (\Throwable $e) {
    $debug = (bool) config('debug');

    Response::json([
        'ok'    => false,
        'error' => $e->getMessage(),
        'code'  => 'exception',
        'where' => $debug ? basename($e->getFile()) . ':' . $e->getLine() : null,
    ], 400);
}
