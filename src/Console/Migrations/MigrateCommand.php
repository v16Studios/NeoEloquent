<?php

namespace Vinelab\NeoEloquent\Console\Migrations;

use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Migrations\Migrator;
use Symfony\Component\Console\Input\InputOption;

class MigrateCommand extends BaseCommand
{
    use ConfirmableTrait;

    /**
     * {@inheritDoc}
     */
    protected $name = 'neo4j:migrate';

    /**
     * {@inheritDoc}
     */
    protected $description = 'Run the database migrations';

    /**
     * The migrator instance.
     *
     * @var \Vinelab\NeoEloquent\Migrations\Migrator
     */
    protected $migrator;

    /**
     * The path to the packages directory (vendor).
     */
    protected $packagePath;

    /**
     * @param \Vinelab\NeoEloquent\Migrations\Migrator $migrator
     * @param string                                   $packagePath
     */
    public function __construct(Migrator $migrator, $packagePath)
    {
        parent::__construct();

        $this->migrator = $migrator;
        $this->packagePath = $packagePath;
    }

    /**
     * {@inheritDoc}
     */
    public function fire()
    {
        if (!$this->confirmToProceed()) {
            return;
        }

        // The pretend option can be used for "simulating" the migration and grabbing
        // the Cypher queries that would fire if the migration were to be run against
        // a database for real, which is helpful for double checking migrations.
        $pretend = $this->input->getOption('pretend');
        $this->migrator->setOutput($this->output);
        $path = $this->getMigrationPath();

        $this->migrator->setConnection($this->input->getOption('database'));
        $this->migrator->run($path, ['pretend' => $pretend]);

        // Finally, if the "seed" option has been given, we will re-run the database
        // seed task to re-populate the database, which is convenient when adding
        // a migration and a seed at the same time, as it is only this command.
        if ($this->input->getOption('seed')) {
            $this->call('db:seed', ['--force' => true]);
        }
    }
}
