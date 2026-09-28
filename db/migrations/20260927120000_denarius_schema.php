<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DenariusSchema extends AbstractMigration
{
    public function change(): void
    {
        $this->table('principals')
            ->addColumn('idp_user_id', 'string', ['limit' => 64])
            ->addColumn('email', 'string', ['limit' => 255])
            ->addColumn('ork_kingdom_id', 'integer', ['null' => true])
            ->addColumn('ork_kingdom_name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('updated_at', 'string', ['limit' => 40])
            ->addIndex(['idp_user_id'], ['unique' => true])
            ->create();

        $this->table('kingdoms')
            ->addColumn('ork_kingdom_id', 'integer')
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('slug', 'string', ['limit' => 255])
            ->addColumn('visibility', 'string', ['limit' => 32, 'default' => 'kingdom_only'])
            ->addColumn('display_mode', 'string', ['limit' => 32, 'default' => 'summarized'])
            ->addColumn('enrollment_id', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('institution_name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('enrollment_status', 'string', ['limit' => 32, 'default' => 'none'])
            ->addColumn('last_synced_at', 'string', ['limit' => 40, 'null' => true])
            ->addIndex(['ork_kingdom_id'], ['unique' => true])
            ->addIndex(['slug'], ['unique' => true])
            ->addIndex(['enrollment_id'])
            ->create();

        $this->auditTable('kingdoms', [
            'ork_kingdom_id' => 'integer',
            'name' => 'string',
            'slug' => 'string',
            'visibility' => 'string',
            'display_mode' => 'string',
            'enrollment_id' => 'string',
            'institution_name' => 'string',
            'enrollment_status' => 'string',
            'last_synced_at' => 'string',
        ]);

        $this->table('published_accounts')
            ->addColumn('kingdom_id', 'integer')
            ->addColumn('teller_account_id', 'string', ['limit' => 128])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('account_type', 'string', ['limit' => 64])
            ->addColumn('last_four', 'string', ['limit' => 4, 'null' => true])
            ->addColumn('published', 'integer', ['default' => 1])
            ->addIndex(['kingdom_id', 'teller_account_id'], ['unique' => true])
            ->create();

        $this->auditTable('published_accounts', [
            'kingdom_id' => 'integer',
            'teller_account_id' => 'string',
            'name' => 'string',
            'account_type' => 'string',
            'last_four' => 'string',
            'published' => 'integer',
        ]);

        $this->table('enrollment_secrets')
            ->addColumn('kingdom_id', 'integer')
            ->addColumn('ciphertext', 'text')
            ->addIndex(['kingdom_id'], ['unique' => true])
            ->create();

        $this->table('transactions')
            ->addColumn('kingdom_id', 'integer')
            ->addColumn('teller_transaction_id', 'string', ['limit' => 128])
            ->addColumn('teller_account_id', 'string', ['limit' => 128])
            ->addColumn('posted_on', 'string', ['limit' => 10])
            ->addColumn('amount_cents', 'integer')
            ->addColumn('category', 'string', ['limit' => 64])
            ->addColumn('description', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('counterparty', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 32, 'null' => true])
            ->addIndex(['teller_transaction_id'], ['unique' => true])
            ->addIndex(['kingdom_id', 'posted_on'])
            ->create();

        $this->table('role_grants')
            ->addColumn('actor_idp_user_id', 'string', ['limit' => 64])
            ->addColumn('target_idp_user_id', 'string', ['limit' => 64])
            ->addColumn('action', 'string', ['limit' => 16])
            ->addColumn('resource', 'string', ['limit' => 64])
            ->addColumn('ork_kingdom_id', 'integer', ['null' => true])
            ->addColumn('created_at', 'string', ['limit' => 40])
            ->create();
    }

    /**
     * @param array<string, string> $columns
     */
    private function auditTable(string $table, array $columns): void
    {
        $audit = $this->table($table . '_audit')
            ->addColumn('audit_id', 'integer')
            ->addColumn('edit_at', 'datetime')
            ->addColumn('edit_fields', 'text', ['null' => true])
            ->addColumn('edited_by_id', 'integer', ['null' => true])
            ->addColumn('operation', 'string', ['limit' => 16]);

        foreach ($columns as $name => $type) {
            $options = ['null' => true];
            if ($type === 'string') {
                $options['limit'] = 512;
            }
            $audit->addColumn($name, $type, $options);
        }

        $audit->create();
    }
}
