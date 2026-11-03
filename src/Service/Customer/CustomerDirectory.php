<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Customer;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Customer\Exception\CustomerException;
use VenPosTPV\Service\Sync\Outbox;
use VenPosTPV\Service\Sync\OutboxSyncTrigger;

/**
 * The till's own copy of the customers (PRD C1, C2): searched without PrestaShop,
 * and where a customer created at the till is stored at once with a local id. Its
 * upload is an outbox row of type `customer`; a sale for that customer waits for
 * that row (Outbox depends_on) and is sent with the PrestaShop id it got.
 *
 * Cart customers carry `local_id` (this table) and `id` (the PrestaShop id, null
 * until uploaded). The server always takes the PrestaShop id from this table, never
 * from the browser; a cart with only `id` (older screens, parked tickets) keeps the
 * previous behaviour.
 */
class CustomerDirectory
{
    private const SEARCH_LIMIT = 20;

    /** PrestaShop's Validate::isCustomerName: no digits nor these symbols, a dot only before a space or the end. */
    private const NAME = '/^(?!\s*$)(?:[^0-9!<>,;?=+()\/\\\\@#"°*`{}_^$%:¤\[\]|\.。]|[\.。](?:\s|$))*$/u';

    private const PHONE = '/^[0-9 +().-]{0,32}$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly ?Outbox $outbox = null,
        private readonly ?OutboxSyncTrigger $syncTrigger = null,
    ) {
    }

    /**
     * Active customers whose name, email or phone contain $query, from the local copy.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        $sql = 'SELECT * FROM customer WHERE active = 1';
        $params = [];
        if ($query !== '') {
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $sql .= " AND (CONCAT(firstname, ' ', lastname) LIKE ? OR email LIKE ? OR phone LIKE ?)";
            $params = [$like, $like, $like];
        }
        $rows = $this->connection->fetchAllAssociative($sql . ' ORDER BY lastname, firstname, id LIMIT ' . self::SEARCH_LIMIT, $params);

        return array_map(fn (array $row): array => $this->view($row), $rows);
    }

    /**
     * Stores a customer created at the till and queues its upload, in one
     * transaction. An active customer with the same email is returned instead
     * (`existing: true`), so the cashier never creates the same person twice.
     *
     * @param array<string, mixed> $input firstname, lastname, email (PrestaShop requires the three), phone
     *
     * @return array<string, mixed> the customer as the screen shows it, plus `existing`
     *
     * @throws CustomerException 422 when the form is not valid
     */
    public function create(array $input): array
    {
        $firstname = $this->name($input['firstname'] ?? null, 'El nombre');
        $lastname = $this->name($input['lastname'] ?? null, 'Los apellidos');
        $email = mb_strtolower(trim(\is_string($input['email'] ?? null) ? $input['email'] : ''));
        if ($email === '' || mb_strlen($email) > 255 || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw CustomerException::invalid('El email no es válido: PrestaShop lo necesita para dar de alta al cliente.');
        }
        $phone = trim(\is_string($input['phone'] ?? null) ? $input['phone'] : '');
        if (preg_match(self::PHONE, $phone) !== 1) {
            throw CustomerException::invalid('El teléfono solo puede llevar números, espacios y + ( ) - . (máximo 32).');
        }

        $existing = $this->connection->fetchAssociative('SELECT * FROM customer WHERE email = ? AND active = 1 ORDER BY id LIMIT 1', [$email]);
        if ($existing !== false) {
            return $this->view($existing) + ['existing' => true];
        }

        $outbox = $this->outbox ?? new Outbox($this->connection);
        $id = $this->connection->transactional(function () use ($outbox, $firstname, $lastname, $email, $phone): int {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->connection->insert('customer', [
                'ps_customer_id' => null,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->connection->lastInsertId();
            $outboxId = $outbox->enqueueCustomer($id, ['firstname' => $firstname, 'lastname' => $lastname, 'email' => $email]);
            $this->connection->executeStatement('UPDATE customer SET outbox_id = ? WHERE id = ?', [$outboxId, $id]);

            return $id;
        });
        // Uploaded right after the response; the minute task covers the rest.
        $this->syncTrigger?->request();

        $row = $this->connection->fetchAssociative('SELECT * FROM customer WHERE id = ?', [$id]);

        return $this->view($row === false ? [] : $row) + ['existing' => false];
    }

    /**
     * The PrestaShop customer of a sale. A local customer not uploaded yet gives
     * id 0 plus its local id and the outbox row the sale must wait for.
     *
     * @return array{ps_customer_id: int, local_id: int|null, depends_on: int|null}
     */
    public function resolveForSale(mixed $customer): array
    {
        if (!\is_array($customer)) {
            return ['ps_customer_id' => 0, 'local_id' => null, 'depends_on' => null];
        }
        if (\is_int($customer['local_id'] ?? null) && $customer['local_id'] > 0) {
            $row = $this->connection->fetchAssociative('SELECT id, ps_customer_id, outbox_id FROM customer WHERE id = ?', [$customer['local_id']]);
            if ($row !== false) {
                if ($row['ps_customer_id'] !== null) {
                    return ['ps_customer_id' => (int) $row['ps_customer_id'], 'local_id' => null, 'depends_on' => null];
                }

                return ['ps_customer_id' => 0, 'local_id' => (int) $row['id'], 'depends_on' => $row['outbox_id'] !== null ? (int) $row['outbox_id'] : null];
            }
        }
        // Previous behaviour: a positive integer id is the PrestaShop customer.
        $id = $customer['id'] ?? null;

        return ['ps_customer_id' => \is_int($id) && $id > 0 ? $id : 0, 'local_id' => null, 'depends_on' => null];
    }

    private function name(mixed $value, string $label): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', \is_string($value) ? $value : '') ?? '');
        if ($name === '') {
            throw CustomerException::invalid($label . ' es obligatorio.');
        }
        if (mb_strlen($name) > 255 || preg_match(self::NAME, $name) !== 1) {
            throw CustomerException::invalid($label . ' no puede llevar números ni símbolos.');
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function view(array $row): array
    {
        $firstname = (string) ($row['firstname'] ?? '');
        $lastname = (string) ($row['lastname'] ?? '');

        return [
            'local_id' => (int) ($row['id'] ?? 0),
            'id' => isset($row['ps_customer_id']) ? (int) $row['ps_customer_id'] : null,
            'name' => trim($firstname . ' ' . $lastname),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'synced' => isset($row['ps_customer_id']),
        ];
    }
}
