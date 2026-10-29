<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class AuthenticationModelTest extends DatabaseTestCase
{
    private string $directory;
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->directory = sys_get_temp_dir() . '/fin-auth-model-' . bin2hex(random_bytes(5));
        mkdir($this->directory, 0700, true);
        $this->config = [
            'auth' => [
                'master_pin_file' => $this->directory . '/master-pin.hash',
                'throttle_file' => $this->directory . '/throttle.json',
                'file_group' => '',
                'pin_failure_delay_us' => 0,
            ],
        ];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function testMissingMasterPinAndCredentialsFailsClosed(): void
    {
        $status = authentication_bootstrap_status($this->db, $this->config);
        self::assertFalse($status['authenticated']);
        self::assertFalse($status['master_pin_configured']);
        self::assertFalse($status['device_login_available']);
        self::assertTrue($status['configuration_required']);
        self::assertNull($status['principal']);
        self::assertNull($status['csrf_token']);
    }

    public function testSinglePrincipalHasNoUsernameOrFinancialOwnership(): void
    {
        $tables = $this->db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotContains('users', $tables);
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM auth_principal')->fetchColumn());
        foreach (['months', 'envelopes', 'entries', 'transactions', 'accounts'] as $table) {
            $columns = array_column($this->db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
            self::assertNotContains('user_id', $columns, $table);
        }
    }

    public function testWebauthnOptionsUseOneLocalNonDiscoverablePlatformCredential(): void
    {
        $config = $this->config + [
            'name' => 'FIN Planner',
            'webauthn_rp_id' => 'localhost',
            'webauthn_origin' => 'http://localhost:8092',
        ];
        $registration = webauthn_registration_options($this->db, $config);
        self::assertSame('localhost', $registration['publicKey']['rp']['id']);
        self::assertSame('platform', $registration['publicKey']['authenticatorSelection']['authenticatorAttachment']);
        self::assertSame('discouraged', $registration['publicKey']['authenticatorSelection']['residentKey']);
        self::assertFalse($registration['publicKey']['authenticatorSelection']['requireResidentKey']);
        self::assertSame('required', $registration['publicKey']['authenticatorSelection']['userVerification']);
        self::assertSame([], $registration['publicKey']['excludeCredentials']);
        self::assertNotEmpty($_SESSION['fin_auth_challenges']['registration']);
        self::assertNull(webauthn_assertion_options($this->db, $config, 'not-enrolled'));

        $credentialId = random_bytes(32);
        $encodedId = fin_base64url_encode($credentialId);
        $insert = $this->db->prepare("INSERT INTO webauthn_credentials (credential_id,public_key,label) VALUES (:credential_id,'test-public-key','Test device')");
        $insert->bindValue(':credential_id', $credentialId, PDO::PARAM_LOB);
        $insert->execute();
        $assertion = webauthn_assertion_options($this->db, $config, $encodedId);
        self::assertSame('localhost', $assertion['publicKey']['rpId']);
        self::assertSame('required', $assertion['publicKey']['userVerification']);
        self::assertCount(1, $assertion['publicKey']['allowCredentials']);
        self::assertSame($encodedId, $assertion['publicKey']['allowCredentials'][0]['id']);
        self::assertSame(['internal'], $assertion['publicKey']['allowCredentials'][0]['transports']);
        self::assertSame($encodedId, $_SESSION['fin_auth_challenges']['assertion']['context']['credential_id']);
    }

    public function testMultipleCredentialsCanBeRevokedIndependently(): void
    {
        $insert = $this->db->prepare("INSERT INTO webauthn_credentials (credential_id,public_key,label) VALUES (:credential_id,'test-public-key',:label)");
        foreach (['Windows laptop' => random_bytes(32), 'Android phone' => random_bytes(32)] as $label => $id) {
            $insert->bindValue(':credential_id', $id, PDO::PARAM_LOB);
            $insert->bindValue(':label', $label);
            $insert->execute();
        }
        self::assertCount(2, public_webauthn_credentials($this->db));
        revoke_webauthn_credential($this->db, 1);
        $remaining = public_webauthn_credentials($this->db);
        self::assertCount(1, $remaining);
        self::assertSame('Android phone', $remaining[0]['label']);
        self::assertCount(1, active_webauthn_credentials($this->db));
        self::assertTrue(authentication_bootstrap_status($this->db, $this->config)['device_login_available']);
    }

    public function testAuthenticatedSessionGetsSamePrincipalRegardlessOfMethod(): void
    {
        complete_fin_authentication('master-pin');
        $pinStatus = authentication_bootstrap_status($this->db, $this->config);
        self::assertSame(['name' => 'owner'], $pinStatus['principal']);
        self::assertNotEmpty($pinStatus['csrf_token']);
        $_SESSION = [];
        complete_fin_authentication('device');
        $deviceStatus = authentication_bootstrap_status($this->db, $this->config);
        self::assertSame($pinStatus['principal'], $deviceStatus['principal']);
        self::assertTrue($deviceStatus['authenticated']);
    }
}
