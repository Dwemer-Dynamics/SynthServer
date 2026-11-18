<?php declare(strict_types=1);

// Global (shared dwemer store) and SYNTH-only API keys. DatabaseTestCase gives every test a disposable unit database and a
// disposable stand-in for the shared store (database dwemer_unit_keys on the unit PostgreSQL server), emptied per test.
require_once __DIR__ . '/DatabaseTestCase.php';
require_once dirname(__DIR__, 2) . '/lib/core/api_badge.class.php';
require_once dirname(__DIR__, 2) . '/lib/core/llm_connector.class.php';

use Synth\Core\Contracts\ApiKeyRef;
use Synth\Core\DbManager\Dao\ApiBadgeDao;
use Synth\Core\SharedApiKeyStore;

final class SharedApiKeysTest extends DatabaseTestCase
{
    private function dao(): ApiBadgeDao
    {
        $GLOBALS['db'] ??= new sql();
        return new ApiBadgeDao($GLOBALS['db']);
    }

    private function shared(): PgSql\Connection
    {
        return pg_connect('host=localhost dbname=dwemer_unit_keys user=dwemer password=dwemer', PGSQL_CONNECT_FORCE_NEW);
    }

    private function sharedRow(ApiKeyRef $ref): array
    {
        return pg_fetch_assoc(pg_query_params($this->shared(), 'SELECT status, revision, secret, label FROM public.api_keys WHERE key_id = $1::uuid', [$ref->id()]));
    }

    // (a) a global key is released only for its own origin.
    public function testGlobalKeyIsNotSentToAnotherOrigin(): void
    {
        $dao = $this->dao();
        $ref = $dao->create('shared', 'OpenAI', 'SYNTHKEY-openai');
        $this->assertNotNull($ref);
        $this->assertSame('SYNTHKEY-openai', $dao->secretForEndpoint($ref, 'https://api.openai.com/v1/chat/completions', 'OpenAI'));
        $this->assertSame('SYNTHKEY-openai', $dao->secretForEndpoint($ref, '', 'OpenAI'));
        $this->assertSame('', $dao->secretForEndpoint($ref, 'https://evil.example.test/v1/chat/completions', 'OpenAI'));
        $this->assertSame('', $dao->secretForEndpoint($ref, 'https://api.openai.com:8443/v1', 'OpenAI'));
        $local = $dao->create('local', 'Lab', 'SYNTHKEY-local');
        $this->assertSame('SYNTHKEY-local', $dao->secretForEndpoint($local, 'https://evil.example.test/v1', ''));
    }

    // (b) a stale expected revision is the conflict contract and changes nothing.
    public function testStaleRevisionConflictsAndChangesNothing(): void
    {
        $dao = $this->dao();
        $ref = $dao->create('shared', 'OpenAI', 'SYNTHKEY-one');
        $rev = (int) $this->sharedRow($ref)['revision'];
        $this->assertFalse($dao->update($ref, null, 'SYNTHKEY-two', $rev + 3));
        $this->assertSame('conflict', $dao->lastError());
        $this->assertSame('conflict', $dao->delete($ref, $rev + 3));
        $row = $this->sharedRow($ref);
        $this->assertSame(['active', (string) $rev, 'SYNTHKEY-one'], [$row['status'], $row['revision'], $row['secret']]);
        $this->assertTrue($dao->update($ref, null, 'SYNTHKEY-two', $rev));
        $this->assertSame((string) ($rev + 1), $this->sharedRow($ref)['revision']);
    }

    // (c) delete keeps a tombstone and a deleted key never resolves.
    public function testDeleteTombstonesAndDeletedKeyNeverResolves(): void
    {
        $dao = $this->dao();
        $ref = $dao->create('shared', 'Groq', 'SYNTHKEY-groq');
        $this->assertSame('deleted', $dao->delete($ref));
        $row = $this->sharedRow($ref);
        $this->assertSame('deleted', $row['status']);
        $this->assertNull($row['secret']);
        $this->assertSame('', $dao->secret($ref));
        $this->assertSame('', $dao->secretForEndpoint($ref, '', 'Groq'));
        $this->assertNull($dao->defaultForProvider('Groq'));
        $this->assertSame('revoked', $dao->delete($ref));
        $this->assertFalse($dao->update($ref, null, 'SYNTHKEY-revive'));
        $this->assertSame('deleted', $this->sharedRow($ref)['status']);
    }

    // (d) at most 64 active keys; a tombstone frees a slot. (The fixture already holds one OpenRouter key.)
    public function testActiveKeyCap(): void
    {
        $dao = $this->dao();
        $first = null;
        for ($i = 1; $i < SharedApiKeyStore::ACTIVE_MAX; $i++) {
            $ref = $dao->create('shared', "Cap $i", "SYNTHKEY-cap-$i", ['provider' => 'custom_openai', 'origin' => 'https://cap.example.test']);
            $this->assertNotNull($ref, "key $i");
            $first ??= $ref;
        }
        $this->assertNull($dao->create('shared', 'Cap over', 'SYNTHKEY-over', ['provider' => 'custom_openai', 'origin' => 'https://cap.example.test']));
        $this->assertSame('limit', $dao->lastError());
        $this->assertSame('deleted', $dao->delete($first));
        $this->assertNotNull($dao->create('shared', 'Cap again', 'SYNTHKEY-again', ['provider' => 'custom_openai', 'origin' => 'https://cap.example.test']));
    }

    // (e) a connector without a named key uses the provider's global key; a SYNTH-only key only when named; a named key that is gone is no key.
    public function testConnectorBindingHasNoSilentFallback(): void
    {
        $dao = $this->dao();
        $global = $dao->create('shared', 'Groq', 'SYNTHKEY-global-or');
        $local = $dao->create('local', 'Groq Lab', 'SYNTHKEY-local-or');
        $url = 'https://api.groq.com/openai/v1/chat/completions';
        $unnamed = ['service' => 'groq', 'url' => $url];
        $this->assertSame('SYNTHKEY-global-or', ApiBadge::keyForConnector($unnamed, 'Groq', $url));
        $this->assertSame('SYNTHKEY-local-or', ApiBadge::keyForConnector($unnamed + ['api_key_id' => $local->toString()], 'Groq', $url));
        $this->assertSame('SYNTHKEY-global-or', ApiBadge::keyForConnector($unnamed + ['api_key_id' => $global->toString()], 'Groq', $url));
        $this->assertSame('SYNTHKEY-global-or', ApiBadge::keyForConnector($unnamed, 'Groq', $url));
        $this->assertSame('deleted', $dao->delete($global));
        $this->assertSame('', ApiBadge::keyForConnector($unnamed + ['api_key_id' => $global->toString()], 'Groq', $url));
        $this->assertSame('', ApiBadge::keyForConnector($unnamed, 'Groq', $url));
        $this->assertSame('SYNTHKEY-local-or', ApiBadge::keyForConnector($unnamed + ['api_key_id' => $local->toString()], 'Groq', $url));
    }

    // (f) public.synth_key_references lists the shared keys SYNTH connectors reference, and nothing else.
    public function testKeyReferencesView(): void
    {
        $dao = $this->dao();
        $global = $dao->create('shared', 'OpenRouter', 'SYNTHKEY-view');
        $local = $dao->create('local', 'Lab', 'SYNTHKEY-view-local');
        $db = $GLOBALS['db'];
        // The unit database is the pre-ledger legacy chain; the view is created by the shipped migration's own statements.
        require_once dirname(__DIR__, 2) . '/core/dbmanager/migrations/Version20261117100000.php';
        foreach (\Synth\Migrations\Version20261117100000::statements() as $statement) {
            $db->execQuery($statement);
        }
        $db->execQuery("INSERT INTO synth.core_llm_connector (label, url, model, api_key_id) VALUES ('Uses global', 'https://openrouter.ai/api/v1/chat/completions', 'm', "
            . $db->escapeLiteral($global->id()) . "::uuid)");
        $db->execQuery("INSERT INTO synth.core_llm_connector (label, url, model, local_api_key_id) VALUES ('Uses local', 'https://lab.example.test/v1', 'm', " . (int) $local->id() . ")");
        $db->execQuery("INSERT INTO synth.core_llm_connector (label, url, model) VALUES ('Uses none', 'https://x.example.test/v1', 'm')");
        $rows = [];
        $result = $db->execQuery('SELECT kind, label, shared_key_id::text AS shared_key_id FROM public.synth_key_references ORDER BY label');
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        $this->assertSame([['kind' => 'model', 'label' => 'Uses global', 'shared_key_id' => $global->id()]], $rows);
    }
}
