<?php

declare(strict_types=1);

namespace Condoedge\Ai\GraphStore;

/**
 * Shared credential handling for Neo4j store implementations.
 *
 * Supports both plain and encrypted passwords for enhanced security.
 * When both are configured, encrypted password takes precedence.
 *
 * Usage:
 * 1. Plain password: Set NEO4J_PASSWORD in .env
 * 2. Encrypted password: Set NEO4J_PASSWORD_ENCRYPTED in .env
 *    Generate with: php artisan tinker >>> encrypt('your_password')
 */
trait Neo4jCredentialsTrait
{
    /**
     * Encrypted password from config (preferred)
     */
    private ?string $encryptedPassword = null;

    /**
     * Plain password from config (fallback for backwards compatibility)
     */
    private ?string $plainPassword = null;

    /**
     * Initialize credentials from config array.
     *
     * @param array $config Configuration with 'password' and/or 'password_encrypted'
     */
    protected function initializeCredentials(array $config): void
    {
        if (!empty($config['password_encrypted'])) {
            $this->encryptedPassword = $config['password_encrypted'];
        } else {
            $this->plainPassword = $config['password'] ?? '';
        }
    }

    /**
     * Get the password, decrypting if necessary.
     *
     * This method resolves credentials on-demand to minimize exposure:
     * - If password_encrypted is set, decrypts using Laravel's decrypt()
     * - Falls back to plain password for backwards compatibility
     *
     * @return string The Neo4j password
     * @throws \Illuminate\Contracts\Encryption\DecryptException If decryption fails
     */
    protected function getPassword(): string
    {
        if ($this->encryptedPassword !== null) {
            return decrypt($this->encryptedPassword);
        }

        return $this->plainPassword ?? '';
    }
}
