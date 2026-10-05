<?php

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Basis für Tests mit Datenbank: Migrationen laufen vor jedem Test frisch
 * gegen getraenkeliste_test. Hilfsmethoden ergänzen spätere Tasks.
 */
abstract class DbTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
}
