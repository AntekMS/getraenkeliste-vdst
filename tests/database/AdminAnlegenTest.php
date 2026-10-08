<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\PersonModel;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class AdminAnlegenTest extends DbTestCase
{
    public function test_legt_admin_ohne_pin_an(): void
    {
        $model = new PersonModel();

        $id     = $model->legeAdminAn('Max', 'Mustermann', 'max', 'geheim1234');
        $person = $model->find($id);

        $this->assertSame('Max Mustermann', $person['anzeigename']);
        $this->assertSame('mitglied', $person['typ']);
        $this->assertSame('aktiv', $person['gruppe']);
        $this->assertSame('max', $person['benutzername']);
        $this->assertTrue(password_verify('geheim1234', $person['passwort_hash']));
        $this->assertNull($person['pin_hash']);
        $this->assertSame(0, (int) $person['passwort_wechsel_erzwingen']);
        $this->assertSame(['mitglied', 'admin'], $model->rollen($id));
    }
}
