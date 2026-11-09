<?php

namespace App\Console\Commands;

use App\Services\PlatformKeyPermissionService;
use DomainException;
use Illuminate\Console\Command;

class ManagePlatformKeyPermission extends Command
{
    protected $signature = 'platform-keys:permission
        {action : grant or revoke}
        {role : Explicit existing protected role}
        {permission : One platforms.keys permission}
        {--actor= : Active security_admin operator ID}
        {--ticket= : Change ticket, e.g. SEC-123}
        {--apply : Apply after password authentication and exact confirmation; otherwise preview only}';

    protected $description = 'Preview or explicitly apply audited platform key permissions to an existing protected role';

    public function handle(PlatformKeyPermissionService $permissions): int
    {
        $action = (string) $this->argument('action');
        $role = (string) $this->argument('role');
        $permission = (string) $this->argument('permission');
        $actorId = (string) $this->option('actor');
        $ticket = (string) $this->option('ticket');
        try {
            if (! in_array($action, ['grant', 'revoke'], true)
                || preg_match('/\A[A-Z][A-Z0-9]{1,15}-[1-9][0-9]{0,9}\z/', $ticket) !== 1) {
                throw new DomainException('Specify grant/revoke and a change ticket such as SEC-123.');
            }
            if ($this->option('apply') && ! $this->input->isInteractive()) {
                throw new DomainException('Apply requires an interactive terminal; --no-interaction is not allowed.');
            }
            $preview = $permissions->preview($actorId, $role, $permission);
            $this->line('Environment: ' . app()->environment());
            $this->line('Database: ' . (new \App\Models\Admin())->getConnection()->getDatabaseName());
            $this->line("Operator: {$actorId}; ticket: {$ticket}; action: {$action}; role: {$role}; permission: {$permission}");
            $this->table(['Affected administrator ID', 'Active'], array_map(
                fn ($member) => [$member['id'], $member['is_active'] ? 'yes' : 'no'],
                $preview['members']
            ));
            $this->line('Current mapping: ' . ($preview['granted'] ? 'granted' : 'absent'));
            $this->warn('Role-wide change: other roles may independently grant the same permission.');
            if (! $this->option('apply')) {
                $this->info('Preview only. No permissions or audit records were changed.');
                return self::SUCCESS;
            }
            $password = (string) $this->secret('Operator password', false);
            $confirmation = strtoupper($action) . ' ' . $permission . ' ' . $role . ' ' . $ticket;
            if ($this->ask('Type exactly: ' . $confirmation) !== $confirmation) {
                throw new DomainException('Confirmation did not match. Nothing changed.');
            }
            try {
                $changed = $permissions->apply($action, $actorId, $role, $permission, $ticket, $password, $preview);
            } finally {
                unset($password);
            }
            $this->info($changed ? 'Permission mapping changed and audited.' : 'Already in the requested state. No change or audit event.');

            return self::SUCCESS;
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
