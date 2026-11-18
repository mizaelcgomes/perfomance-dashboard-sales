<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Config;
use App\Core\Session;

/**
 * Login do admin (usuário e senha do config.secrets.php) com limite de tentativas por IP: 5 falhas bloqueiam por 15 minutos.
 * Mesmas regras e mesmas mensagens do login do api.php de antes; o contador agora fica em storage/rate-limit/admin.json (fora da pasta pública).
 */
final class AutenticacaoAdminService
{
    private const MAXIMO = 5;
    private const JANELA = 900;

    private LimiteDeTentativas $limite;

    public function __construct(private Database $banco, ?LimiteDeTentativas $limite = null)
    {
        $this->limite = $limite ?? new LimiteDeTentativas(Config::storage() . '/rate-limit/admin.json');
    }

    public function logado(): bool
    {
        return Session::obter('admin_logged') === true;
    }

    /** @return array{success: bool, message: string} */
    public function entrar(string $usuario, string $senha, string $ip): array
    {
        $espera = $this->limite->bloqueado('admin', $ip, self::MAXIMO, self::JANELA);
        if ($espera > 0) {
            return ['success' => false, 'message' => LimiteDeTentativas::mensagem($espera)];
        }
        $usuario = trim($usuario);
        if (hash_equals($this->banco->segredo('ADMIN_USER'), $usuario) && password_verify($senha, $this->banco->segredo('ADMIN_PASS_HASH'))) {
            $this->limite->limpar('admin', $ip);
            Session::regenerar();
            Session::definir('admin_logged', true);
            Session::definir('admin_user', $usuario);
            return ['success' => true, 'message' => 'Login realizado'];
        }
        $this->limite->falhou('admin', $ip, self::JANELA);
        return ['success' => false, 'message' => 'Usuário ou senha incorretos'];
    }

    public function sair(): void
    {
        Session::esquecer('admin_logged');
        Session::esquecer('admin_user');
    }

    public function tokenCsrf(): string
    {
        return Csrf::token();
    }
}
