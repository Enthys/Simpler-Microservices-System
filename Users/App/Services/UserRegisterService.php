<?php declare(strict_types=1);

namespace App\Services;

use App\Connection;
use App\Exception\DuplicateUserException;
use App\Exception\QueryException;
use App\Models\User;
use App\Services\Payloads\UserRegisterPayload;

class UserRegisterService extends Service
{
    public const CHANNEL = 'onUserRegisterRequest';

    public const PAYLOAD_CLASS = UserRegisterPayload::class;

    public const QUEUE_USER_REGISTER_RESULTS = 'userRegisterResult';

    public function getWritingQueues(): array
    {
        return [
            static::QUEUE_USER_REGISTER_RESULTS
        ];
    }

    /**
     * @param UserRegisterPayload $payload
     * @return mixed
     * @throws DuplicateUserException
     * @throws QueryException
     */
    public function run(UserRegisterPayload $payload): void
    {
        $user = $this->getUser($payload->username);
        if ($user) {
            throw new DuplicateUserException(sprintf('Username `%1$s` is already taken.', $payload->username));
        }

        $password = password_hash($payload->password, PASSWORD_BCRYPT);
        $result = $this->registerUser($payload->username, $password);
        if (!$result) {
            throw new QueryException('Could not add user to database');
        }

        $this->broker->publish(static::QUEUE_USER_REGISTER_RESULTS, [
            'id' => Connection::connect()->lastInsertId(),
            'username' => $payload->username,
            'password' => $password
        ]);
    }

    private function registerUser(string $username, string $password): bool
    {
        $db = Connection::connect();
        $stmt = $db->prepare('INSERT INTO `users`(`username`, `password`, `created_on`) VALUES (:username, :password, NOW())');
        return $stmt->execute([
            'username' => $username,
            'password' => password_hash($password, PASSWORD_BCRYPT)
        ]);
    }

    private function getUser(string $username): User
    {
        $db = Connection::connect();
        $stmt = $db->prepare('SELECT * FROM `users` WHERE username = :username');
        $stmt->execute([
            'username' => $username
        ]);

        return new User($stmt->fetch(\PDO::FETCH_ASSOC));
    }

}