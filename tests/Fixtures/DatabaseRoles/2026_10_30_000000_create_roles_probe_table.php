<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Migration de teste (tests/Feature/Database/DatabaseRolesTest.php): cria
// uma tabela e grava nela o papel com que a migration rodou.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tws_roles_probe', function ($table): void {
            $table->id();
            $table->string('migrated_by');
        });

        DB::table('tws_roles_probe')->insert(['migrated_by' => DB::scalar('SELECT current_user')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tws_roles_probe');
    }
};
