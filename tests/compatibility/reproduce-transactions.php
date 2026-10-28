<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

$mode = $argv[1] ?? '';
if (getenv('NEOELOQUENT_DISPOSABLE_GRAPH') !== '1' || !in_array($mode, ['broken', 'fixed'], true)) {
    fwrite(STDERR, "Use the disposable Compose harness: php tests/compatibility/reproduce-transactions.php broken|fixed\n");
    exit(1);
}
$connection = new Vinelab\NeoEloquent\Connection(['scheme' => 'bolt', 'host' => '127.0.0.1', 'port' => 7687, 'database' => 'neo4j']);
$observer = Laudis\Neo4j\ClientBuilder::create()->withDriver('default', 'bolt://127.0.0.1:7687')->build();
$fixture = bin2hex(random_bytes(16));
$failure = new RuntimeException('Synthetic failure after raw write');
$caught = null;
try {
    try {
        $connection->transaction(function ($db) use ($fixture, $failure) {
            $db->statement('CREATE (:TransactionReproduction {fixture: $fixture})', ['fixture' => $fixture]);
            throw $failure;
        });
    } catch (Throwable $error) {
        $caught = $error;
    }
    $count = $observer->run('MATCH (n:TransactionReproduction {fixture: $fixture}) RETURN count(n) AS count', ['fixture' => $fixture])->first()->get('count');
    echo json_encode(['mode' => $mode, 'nodes_after_failure' => $count, 'original_error_preserved' => $caught === $failure, 'caught_class' => $caught ? get_class($caught) : null, 'caught_message' => $caught?->getMessage(), 'transaction_level' => $connection->transactionLevel()], JSON_PRETTY_PRINT), "\n";
    $valid = $mode === 'broken' ? $count === 1 && $caught !== $failure : $count === 0 && $caught === $failure && $connection->transactionLevel() === 0;
} finally {
    $observer->run('MATCH (n:TransactionReproduction {fixture: $fixture}) DETACH DELETE n', ['fixture' => $fixture])->preload();
}
exit($valid ? 0 : 1);
