<?php

declare(strict_types=1);

namespace SwooleFabric\Security;

/**
 * PolicyEngine — evaluates RBAC + ABAC rules.
 *
 * Roles are checked first (RBAC), then conditions (ABAC).
 * All decisions are logged for audit.
 */
final class PolicyEngine
{
    /** @var array<string, list<string>> role → [permission, ...] */
    private array $rolePermissions = [];

    /** @var list<array{resource: string, action: string, condition: callable(array): bool}> */
    private array $abacRules = [];

    /** @var callable(array): void|null */
    private mixed $auditLogger = null;

    /**
     * Define permissions for a role.
     *
     * @param string $role
     * @param list<string> $permissions Format: "resource:action" (e.g. "rooms:create", "messages:read")
     */
    public function defineRole(string $role, array $permissions): void
    {
        $this->rolePermissions[$role] = $permissions;
    }

    /**
     * Add an ABAC condition rule.
     *
     * @param string $resource
     * @param string $action
     * @param callable(array): bool $condition fn(context) → allowed
     */
    public function addCondition(string $resource, string $action, callable $condition): void
    {
        $this->abacRules[] = [
            'resource' => $resource,
            'action' => $action,
            'condition' => $condition,
        ];
    }

    /**
     * Set audit logger.
     *
     * @param callable(array): void $logger fn(decision)
     */
    public function setAuditLogger(callable $logger): void
    {
        $this->auditLogger = $logger;
    }

    /**
     * Evaluate whether an action is allowed.
     *
     * @param string $actorId
     * @param list<string> $roles Actor's roles
     * @param string $resource Resource being accessed
     * @param string $action Action being performed
     * @param array $context Additional context (tenant_id, ip, time, etc.)
     * @return bool
     */
    public function evaluate(
        string $actorId,
        array $roles,
        string $resource,
        string $action,
        array $context = [],
        ): bool
    {
        $permission = "{$resource}:{$action}";

        // 1. RBAC check — does any role grant this permission?
        $rbacAllowed = false;
        foreach ($roles as $role) {
            $perms = $this->rolePermissions[$role] ?? [];
            if (in_array($permission, $perms, true) || in_array("{$resource}:*", $perms, true) || in_array('*:*', $perms, true)) {
                $rbacAllowed = true;
                break;
            }
        }

        // 2. ABAC check — if RBAC allows, check additional conditions
        $abacAllowed = true;
        if ($rbacAllowed) {
            foreach ($this->abacRules as $rule) {
                if ($rule['resource'] === $resource && $rule['action'] === $action) {
                    if (!($rule['condition'])($context)) {
                        $abacAllowed = false;
                        break;
                    }
                }
            }
        }

        $allowed = $rbacAllowed && $abacAllowed;

        // 3. Audit log
        $decision = [
            'actor_id' => $actorId,
            'roles' => $roles,
            'resource' => $resource,
            'action' => $action,
            'permission' => $permission,
            'allowed' => $allowed,
            'rbac' => $rbacAllowed,
            'abac' => $abacAllowed,
            'timestamp' => microtime(true),
        ];

        if ($this->auditLogger !== null) {
            ($this->auditLogger)($decision);
        }

        return $allowed;
    }
}
