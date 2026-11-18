<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Conexão com o banco (PDO), preguiçosa: só conecta na primeira consulta. Usa as MESMAS credenciais do sistema atual
 * (as constantes DB_* do config.secrets.php), então a senha continua em um lugar só.
 *
 * Mesmas opções do config.php atual: exceções, fetch associativo e prepares de verdade no MySQL (sem emulação),
 * o que, junto com parâmetros sempre separados do SQL, é a defesa contra SQL Injection.
 */
final class Database
{
    private ?PDO $pdo = null;

    /** @param array<string, string>|null $config host, banco, usuario, senha, charset (null = ler do config.secrets.php) */
    public function __construct(private ?array $config = null)
    {
    }

    /**
     * Reaproveita a conexão que o sistema atual (config.php) já abriu. Usado pela "ponte" do api.php: o código novo roda dentro
     * da mesma requisição e não abre uma segunda conexão com o banco.
     */
    public static function daPdo(PDO $pdo): self
    {
        $banco = new self(null);
        $banco->pdo = $pdo;
        return $banco;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c = $this->configuracao();
            $this->pdo = new PDO(
                'mysql:host=' . $c['host'] . ';dbname=' . $c['banco'] . ';charset=' . $c['charset'],
                $c['usuario'],
                $c['senha'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }
        return $this->pdo;
    }

    /** @param array<int|string, mixed> $parametros @return array<int, array<string, mixed>> */
    public function consultar(string $sql, array $parametros = []): array
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($parametros);
        return $st->fetchAll();
    }

    /** Primeira linha ou null. @param array<int|string, mixed> $parametros @return array<string, mixed>|null */
    public function linha(string $sql, array $parametros = []): ?array
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($parametros);
        $linha = $st->fetch();
        return $linha === false ? null : $linha;
    }

    /** Primeira coluna da primeira linha (ex.: COUNT(*)). @param array<int|string, mixed> $parametros @return mixed */
    public function valor(string $sql, array $parametros = [])
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($parametros);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** INSERT/UPDATE/DELETE: devolve quantas linhas mudaram. @param array<int|string, mixed> $parametros */
    public function executar(string $sql, array $parametros = []): int
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($parametros);
        return $st->rowCount();
    }

    public function idInserido(): string
    {
        return (string) $this->pdo()->lastInsertId();
    }

    /** Roda $acao dentro de uma transação: confirma se terminar, desfaz se lançar exceção. @return mixed */
    public function transacao(callable $acao)
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $resultado = $acao($this);
            $pdo->commit();
            return $resultado;
        } catch (Throwable $erro) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $erro;
        }
    }

    /**
     * Diagnóstico para o /health: conecta e consulta a versão. Nunca devolve host, usuário, senha nem caminho:
     * só a classe do erro e o código (ex.: 1045 = acesso negado, 2002 = servidor não respondeu).
     * @return array{ok: bool, versao?: string, erro?: string}
     */
    public function testar(): array
    {
        try {
            return ['ok' => true, 'versao' => (string) $this->valor('SELECT VERSION()')];
        } catch (PDOException $e) {
            $codigo = isset($e->errorInfo[1]) ? (string) $e->errorInfo[1] : (string) $e->getCode();
            return ['ok' => false, 'erro' => 'PDOException ' . $codigo];
        } catch (Throwable $e) {
            // só a nossa própria mensagem de configuração ganha texto; qualquer outro erro mostra apenas a classe
            $classe = (new \ReflectionClass($e))->getShortName();
            return ['ok' => false, 'erro' => $e instanceof RuntimeException ? $classe . ': ' . $e->getMessage() : $classe];
        }
    }

    /** @return array{host: string, banco: string, usuario: string, senha: string, charset: string} */
    private function configuracao(): array
    {
        if ($this->config !== null) {
            return $this->config + ['charset' => 'utf8mb4'];
        }
        $this->carregarSegredos();
        return [
            'host' => (string) DB_HOST,
            'banco' => (string) DB_NAME,
            'usuario' => (string) DB_USER,
            'senha' => (string) DB_PASS,
            'charset' => (string) Config::obter('database.charset', 'utf8mb4'),
        ];
    }

    /** Uma constante do config.secrets.php (ex.: ADMIN_USER), que o núcleo carrega uma vez só. */
    public function segredo(string $constante): string
    {
        $this->carregarSegredos();
        return defined($constante) ? (string) constant($constante) : '';
    }

    private function carregarSegredos(): void
    {
        if (!defined('DB_HOST')) {
            $fixo = Config::obter('database.segredos');
            $candidatos = is_string($fixo) && $fixo !== '' ? [$fixo] : (array) Config::obter('database.segredos_candidatos', []);
            foreach ($candidatos as $arquivo) {
                if (is_string($arquivo) && is_file($arquivo)) {
                    require_once $arquivo;
                    break;
                }
            }
        }
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
            throw new RuntimeException('Arquivo config.secrets.php não encontrado (ver config/database.php e config/local.exemplo.php).');
        }
    }
}
