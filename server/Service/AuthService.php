<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Db;
use App\Core\Session;
use RuntimeException;

/**
 * Felhasználók, belépés, fiókok.
 *
 * Egy felhasználóhoz több fiók (portfólió) tartozhat — így ugyanaz az ember
 * futtathat párhuzamosan egy óvatos és egy vakmerő stratégiát.
 */
final class AuthService
{
    public static function register(string $username, string $password, float $capital, string $accountName = ''): array
    {
        $username = trim($username);

        if (mb_strlen($username) < 3 || mb_strlen($username) > 32) {
            throw new RuntimeException('A felhasználónév 3 és 32 karakter között legyen.');
        }
        if (!preg_match('/^[\p{L}\p{N}._-]+$/u', $username)) {
            throw new RuntimeException('A felhasználónév csak betűt, számot, pontot, kötőjelet és aláhúzást tartalmazhat.');
        }
        if (mb_strlen($password) < 6) {
            throw new RuntimeException('A jelszó legalább 6 karakter legyen.');
        }
        if (Db::one('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
            throw new RuntimeException('Ez a felhasználónév már foglalt.');
        }

        $capital = self::validateCapital($capital);

        return Db::transaction(function () use ($username, $password, $capital, $accountName) {
            $userId = Db::insert('users', [
                'username'   => $username,
                'pass_hash'  => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => time(),
            ]);

            $accountId = self::createAccount($userId, $accountName ?: 'Fő portfólió', $capital);
            Session::login($userId, $accountId);

            return ['user_id' => $userId, 'account_id' => $accountId];
        });
    }

    public static function login(string $username, string $password): array
    {
        $user = Db::one('SELECT * FROM users WHERE username = ?', [trim($username)]);

        // Ugyanaz az üzenet rossz névre és rossz jelszóra: ne lehessen
        // kitalálni belőle, hogy létezik-e az adott felhasználó.
        if ($user === null || !password_verify($password, $user['pass_hash'])) {
            throw new RuntimeException('Hibás felhasználónév vagy jelszó.');
        }

        $account = Db::one('SELECT * FROM accounts WHERE user_id = ? ORDER BY id LIMIT 1', [$user['id']]);
        if ($account === null) {
            $accountId = self::createAccount((int) $user['id'], 'Fő portfólió', (float) config('default_capital'));
        } else {
            $accountId = (int) $account['id'];
        }

        Session::login((int) $user['id'], $accountId);

        return ['user_id' => (int) $user['id'], 'account_id' => $accountId];
    }

    public static function createAccount(int $userId, string $name, float $capital): int
    {
        $capital = self::validateCapital($capital);
        $name    = trim($name) !== '' ? trim($name) : 'Portfólió';

        if (mb_strlen($name) > 40) {
            $name = mb_substr($name, 0, 40);
        }

        return Db::insert('accounts', [
            'user_id'         => $userId,
            'name'            => $name,
            'cash_huf'        => $capital,
            'initial_capital' => $capital,
            'created_at'      => time(),
        ]);
    }

    private static function validateCapital(float $capital): float
    {
        $min = (float) config('min_capital');
        $max = (float) config('max_capital');

        if ($capital <= 0) {
            return (float) config('default_capital');
        }
        if ($capital < $min || $capital > $max) {
            throw new RuntimeException(sprintf(
                'A kezdőtőke %s és %s forint között legyen.',
                number_format($min, 0, ',', ' '),
                number_format($max, 0, ',', ' ')
            ));
        }

        return round($capital, 2);
    }

    /** A bejelentkezett felhasználó fiókjai. */
    public static function accounts(int $userId): array
    {
        return Db::all(
            'SELECT id, name, cash_huf, initial_capital, created_at FROM accounts WHERE user_id = ? ORDER BY id',
            [$userId]
        );
    }

    /**
     * Az aktuális fiók azonosítója, ellenőrizve, hogy tényleg a bejelentkezett
     * felhasználóé. Enélkül egy kitalált azonosítóval más portfóliójával
     * lehetne kereskedni.
     */
    public static function currentAccountId(): int
    {
        $userId    = Session::userId();
        $accountId = Session::accountId();

        if ($userId === null || $accountId === null) {
            throw new RuntimeException('Nincs bejelentkezve.');
        }

        $owned = Db::one('SELECT id FROM accounts WHERE id = ? AND user_id = ?', [$accountId, $userId]);
        if ($owned === null) {
            throw new RuntimeException('A kiválasztott fiók nem érhető el.');
        }

        return $accountId;
    }

    public static function switchAccount(int $accountId): void
    {
        $userId = Session::userId();
        if ($userId === null) {
            throw new RuntimeException('Nincs bejelentkezve.');
        }

        $owned = Db::one('SELECT id FROM accounts WHERE id = ? AND user_id = ?', [$accountId, $userId]);
        if ($owned === null) {
            throw new RuntimeException('Nincs ilyen fiókod.');
        }

        Session::selectAccount($accountId);
    }
}
