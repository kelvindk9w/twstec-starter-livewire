<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Frontends: preparação do model User para os dois painéis.
//
// - is_admin: flag de acesso ao super admin Filament (/admin). Deny-by-default:
//   só admin + conta ativa entram (ver User::canAccessPanel). Promoção SÓ via
//   comando artisan `user:make-admin` — nunca por mass assignment.
// - avatar_upload_id: avatar do perfil (registro da tabela uploads,
//   que passou pela validação de segurança + re-encode GD). A chave
//   estrangeira só existe com a tabela `uploads` — o pacote
//   twstec/kit-uploads é OPCIONAL; sem ele a coluna fica vazia para sempre
//   (ninguém envia foto), e a instalação continua de pé.
// - notification_preferences: JSON de preferências de notificação por e-mail
//   (esqueleto — preparado para as notificações do projeto que herdar o
//   kit, ainda sem motor). Chaves/defaults em config/notifications.php.
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('status');
            $avatar = $table->foreignId('avatar_upload_id')->nullable()->after('is_admin');

            if (Schema::hasTable('uploads')) {
                $avatar->constrained('uploads')->nullOnDelete();
            }
            $table->json('notification_preferences')->nullable()->after('avatar_upload_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            Schema::hasTable('uploads')
                ? $table->dropConstrainedForeignId('avatar_upload_id')
                : $table->dropColumn('avatar_upload_id');
            $table->dropColumn(['is_admin', 'notification_preferences']);
        });
    }
};
