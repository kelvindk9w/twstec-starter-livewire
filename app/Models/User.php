<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\OptionalAdminPanelAccess;
use App\Models\Concerns\OptionalProfilePhoto;
use App\Models\Contracts\OptionalAdminPanelUser;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Models\Concerns\KitAuthenticatable;
use Twstec\Kit\Foundation\Identifiers\HasPublicCode;
use Twstec\Kit\Foundation\Identifiers\RoutesByUuid;

/**
 * Usuário da plataforma — o model é do APLICATIVO e compõe o que cada pacote
 * instalado traz.
 *
 * Identificadores (3 camadas — anti-enumeração):
 * - `id` interno: NUNCA exposto.
 * - `uuid`: identificador externo seguro (UUID v7 ordered via HasUuids).
 * - `codigo_publico`: legível, `USR-xxxxxx` (HasPublicCode + UNIQUE no banco).
 *
 * Autenticação (pacote twstec/kit-auth — trait KitAuthenticatable e contrato
 * AuthUser): status da conta (deny-by-default), senha de TRANSAÇÃO com hash
 * separado da senha de login (ambas Argon2id via config/hashing.php),
 * verificação em duas etapas, contas protegidas por extensão, verificação de
 * e-mail e recuperação de senha com os e-mails do kit no idioma da conta.
 *
 * Dados pessoais (criptografia em repouso conforme a classificação do dado):
 * - `name`: cast `encrypted` (AES-256-GCM da APP_KEY) — dado pessoal sensível.
 * - `email`: texto (é a chave de lookup do login; índice UNIQUE exige texto).
 *
 * Painel /admin (Filament): `canAccessPanel` pela trait AccessesAdminPanel,
 * do pacote twstec/kit-admin (deny-by-default: só `is_admin` + conta ativa;
 * o pacote confere o mesmo critério no painel). Foto de perfil (`avatar()`,
 * `avatarUrl()`): trait HasAvatar, do pacote twstec/kit-uploads (a coluna
 * `avatar_upload_id` é da migration de usuários do aplicativo).
 *
 * MÓDULOS OPCIONAIS: o /admin e a foto de perfil vêm de pacotes que quem
 * instala pode deixar de fora (`php artisan tws:install`). Por isso o model
 * não nomeia as peças deles direto: usa OptionalAdminPanelUser,
 * OptionalAdminPanelAccess e OptionalProfilePhoto, que viram a peça do pacote
 * quando ele está instalado e uma peça neutra quando não está (sem painel;
 * sem foto — `avatarUrl()` null, as telas desenham as iniciais). Ver
 * app/Support/optional-modules.php.
 *
 * NOME ANTIGO: até a 1.x este model era App\Core\Auth\Models\User. O nome
 * antigo continua resolvendo para esta classe (app/Support/legacy-aliases.php)
 * — payload de fila serializado antes da atualização o carrega.
 */
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'transaction_password', 'remember_token'])]
class User extends Authenticatable implements AuthUser, HasLocalePreference, OptionalAdminPanelUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicCode, HasUuids, KitAuthenticatable, Notifiable, OptionalAdminPanelAccess, OptionalProfilePhoto, RoutesByUuid;

    /**
     * Prefixo do código público legível: USR-xxxxxx.
     */
    protected const PUBLIC_CODE_PREFIX = 'USR';

    /**
     * Defaults da instância nova (espelham os defaults das migrations) — sem
     * eles, status/is_admin ficam null até o primeiro refresh após o INSERT.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'is_admin' => false,
    ];

    /**
     * Coluna preenchida automaticamente com UUID na criação (HasUuids).
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * Os casts das colunas de autenticação (`status`, `transaction_password`,
     * `transaction_password_set_at`, `two_factor_enabled_at`) vêm das traits
     * do pacote twstec/kit-auth.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * Nome antigo de isReservedAccount(), mantido por compatibilidade (a suíte
     * das contas demo ainda o usa). O produto chama isReservedAccount(); este
     * sai na 3.0.
     *
     * @deprecated Use isReservedAccount().
     */
    public function isDemo(): bool
    {
        return $this->isReservedAccount();
    }

    /**
     * Preferência de notificação efetiva (escolha gravada → default do
     * config/notifications.php). Chaves desconhecidas = false.
     */
    public function notificationPreference(string $key): bool
    {
        $saved = $this->notification_preferences[$key] ?? null;

        if (is_bool($saved)) {
            return $saved;
        }

        return (bool) data_get(config('notifications.preferences'), "{$key}.default", false);
    }
}
