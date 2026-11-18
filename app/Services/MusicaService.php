<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Arquivos de música da TV: a playlist de fundo (music/bg) e a trilha da cerimônia (music/ceremony), na raiz do site.
 * Só mexe em arquivos MP3, com o nome limpo, e nunca fora dessas pastas.
 */
final class MusicaService
{
    private const TAMANHO_MAXIMO = 25 * 1024 * 1024;

    public function __construct(private EnvioDeArquivo $envio)
    {
    }

    /** Pasta da playlist de fundo, com barra no fim. */
    public function pastaFundo(): string
    {
        return $this->raiz() . '/music/bg/';
    }

    /** Pasta da trilha da cerimônia, com barra no fim. */
    public function pastaCerimonia(): string
    {
        return $this->raiz() . '/music/ceremony/';
    }

    /** Músicas da playlist de fundo, em ordem alfabética. @return array<int, array{name: string, url: string, size: int|false}> */
    public function listarFundo(): array
    {
        $dir = $this->pastaFundo();
        $arquivos = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '*.mp3') ?: [] as $f) {
                $nome = basename($f);
                $arquivos[] = ['name' => $nome, 'url' => 'music/bg/' . $nome, 'size' => filesize($f)];
            }
        }
        return $arquivos;
    }

    /**
     * As músicas que a página da TV pode tocar: as de comemoração (MP3 soltos na raiz do site e em music/) e as de fundo (music/bg).
     * Sem nenhuma de comemoração, a TV procura "celebration.mp3". Reproduz a lista que o index.php sempre montou.
     * @return array{celebracao: array<int, string>, fundo: array<int, string>}
     */
    public function trilhasDaTv(): array
    {
        $raiz = $this->raiz();
        $celebracao = [];
        foreach (glob($raiz . '/*.mp3') ?: [] as $t) {
            $celebracao[] = basename($t);
        }
        if (is_dir($raiz . '/music')) {
            foreach (glob($raiz . '/music/*.mp3') ?: [] as $t) {
                $celebracao[] = 'music/' . basename($t);
            }
        }
        $celebracao = array_values(array_unique($celebracao));
        if (!$celebracao) {
            $celebracao = ['celebration.mp3'];
        }
        $fundo = [];
        if (is_dir($raiz . '/music/bg')) {
            foreach (glob($raiz . '/music/bg/*.mp3') ?: [] as $t) {
                $fundo[] = 'music/bg/' . basename($t);
            }
        }
        return ['celebracao' => $celebracao, 'fundo' => $fundo];
    }

    /** O nome é de um MP3 (e só o nome: sem pastas)? */
    public function nomeValido(string $nome): bool
    {
        return $nome !== '' && strtolower(substr($nome, -4)) === '.mp3';
    }

    public function existeNoFundo(string $nome): bool
    {
        return file_exists($this->pastaFundo() . $nome);
    }

    /** Apaga uma música da playlist de fundo (se existir). */
    public function removerDoFundo(string $nome): void
    {
        $caminho = $this->pastaFundo() . $nome;
        if (file_exists($caminho)) {
            unlink($caminho);
        }
    }

    /** Apaga a trilha antiga da cerimônia (se existir). */
    public function removerDaCerimonia(string $nome): void
    {
        $caminho = $this->pastaCerimonia() . $nome;
        if (file_exists($caminho)) {
            unlink($caminho);
        }
    }

    /**
     * Confere o arquivo enviado: precisa ser .mp3 e ter até 25 MB. Devolve a mensagem de erro, ou null se estiver tudo certo.
     * @param array<string, mixed> $arquivo
     */
    public function validar(array $arquivo): ?string
    {
        $ext = strtolower(pathinfo((string) $arquivo['name'], PATHINFO_EXTENSION));
        if ($ext !== 'mp3') {
            return 'Somente arquivos MP3 são aceitos';
        }
        if ($arquivo['size'] > self::TAMANHO_MAXIMO) {
            return 'Arquivo muito grande (máx 25 MB)';
        }
        return null;
    }

    /** Troca tudo que não for letra, número, "_", "." ou "-" por "_". */
    public function limparNome(string $nome): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_.-]/', '_', $nome);
    }

    /** Move o arquivo enviado para $pasta/$nome (cria a pasta se preciso). Devolve false se não conseguir. @param array<string, mixed> $arquivo */
    public function guardar(array $arquivo, string $pasta, string $nome): bool
    {
        if (!is_dir($pasta)) {
            mkdir($pasta, 0755, true);
        }
        return $this->envio->mover((string) $arquivo['tmp_name'], $pasta . $nome);
    }

    /** Raiz do site (onde ficam uploads/ e music/): config app.raiz_site, ou a pasta acima da pasta pública do núcleo. */
    private function raiz(): string
    {
        $definida = Config::obter('app.raiz_site');
        $raiz = is_string($definida) && $definida !== '' ? $definida : Config::publico();
        return rtrim(str_replace('\\', '/', $raiz), '/');
    }
}
