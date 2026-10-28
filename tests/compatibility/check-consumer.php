<?php

if (getenv('NEOELOQUENT_DISPOSABLE_GRAPH') !== '1' || !preg_match('/^[a-f0-9]{40}$/', $argv[1] ?? '')) {
    fwrite(STDERR, "Requires disposable Compose graph and expected 40-character source commit argument.\n");
    exit(1);
}
$root = dirname(__DIR__, 2);
require $root.'/vendor/release-consumer/vendor/autoload.php';

use Composer\InstalledVersions;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Application;
use Vinelab\NeoEloquent\Eloquent\Model;
use Vinelab\NeoEloquent\NeoEloquentServiceProvider;

function verifyConsumer(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class ConsumerReleaseNode extends Model
{
    protected $label = 'ConsumerReleaseFixture';
    protected $guarded = [];
    public $timestamps = false;

    public function children()
    {
        return $this->hasMany(self::class, 'CONSUMER_OWNS');
    }
}

$expected = $argv[1];
$actual = InstalledVersions::getReference('ulobby/neoeloquent');
verifyConsumer($actual === $expected, 'Installed fork source does not match the accepted immutable commit.');
$source = (new ReflectionClass(Model::class))->getFileName();
verifyConsumer(str_starts_with($source, $root.'/vendor/release-consumer/vendor/ulobby/neoeloquent/'), 'Consumer loaded development source instead of its installed package.');
$app = new Application($root.'/vendor/release-consumer');
$app->instance('config', new Repository(['database' => [
    'default' => 'neo4j', 'connections' => ['neo4j' => [
        'driver' => 'neo4j', 'scheme' => 'bolt', 'host' => '127.0.0.1', 'port' => 7687, 'database' => 'neo4j',
    ]],
]]));
$app->register(DatabaseServiceProvider::class);
$app->register(NeoEloquentServiceProvider::class);
$app->boot();
$connection = $app['db']->connection();
verifyConsumer($connection instanceof \Vinelab\NeoEloquent\Connection, 'Provider did not register the graph connection.');
verifyConsumer($connection->getName() === 'neo4j', 'Provider did not preserve the connection name.');
try {
    $id = $connection->transaction(function () {
        $owner = ConsumerReleaseNode::create(['name' => 'owner']);
        $child = ConsumerReleaseNode::create(['name' => 'child']);
        $owner->children()->attach($child, ['quantity' => 0]);
        return $owner->getKey();
    });
    verifyConsumer(ConsumerReleaseNode::findOrFail($id)->children()->count() === 1, 'Installed model/relationship commit failed.');
    $original = new RuntimeException('Expected consumer rollback');
    try {
        $connection->transaction(function () use ($original) {
            ConsumerReleaseNode::create(['name' => 'rolled-back']);
            throw $original;
        });
        throw new RuntimeException('Rollback callback unexpectedly returned.');
    } catch (Throwable $caught) {
        verifyConsumer($caught === $original, 'Installed fork replaced the original callback failure.');
    }
    verifyConsumer(ConsumerReleaseNode::where('name', 'rolled-back')->count() === 0, 'Consumer rollback left a persisted node.');
    verifyConsumer($connection->transactionLevel() === 0, 'Consumer transaction state was not cleared.');
    $row = $connection->select('RETURN $map.value AS value, size($empty) AS size, $id AS id', [
        'map' => ['value' => false], 'empty' => [], 'id' => 0,
    ])->getResults()[0];
    verifyConsumer($row === ['value' => false, 'size' => 0, 'id' => 0], 'Consumer parameter shapes changed.');
    echo json_encode([
        'framework' => Application::VERSION,
        'fork_version' => InstalledVersions::getPrettyVersion('ulobby/neoeloquent'),
        'fork_reference' => $actual,
        'installed_package_source' => true,
        'provider_and_named_connection' => 'pass',
        'model_and_edge_commit' => 'pass',
        'rollback_and_original_error' => 'pass',
        'parameter_shapes' => 'pass',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
} finally {
    $connection->statement('MATCH (n:ConsumerReleaseFixture) DETACH DELETE n');
}
