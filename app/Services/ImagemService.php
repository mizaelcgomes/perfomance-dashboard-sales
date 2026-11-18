<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Endereço das fotos dos closers e dos logos das equipes (pasta uploads/ da raiz do site). Reproduz o buildImageUrl do api.php:
 * "uploads/<arquivo>?v=<data do arquivo>", com a data para o navegador renovar a imagem quando ela for trocada.
 * O endereço é relativo à raiz do site (como o admin.php e a TV esperam), mesmo quando o núcleo está montado em /nova.
 */
final class ImagemService
{
    /** Tamanho máximo da foto (500 KB), o mesmo UPLOAD_MAX_SIZE do config.php. */
    private const TAMANHO_MAXIMO = 500 * 1024;

    public function url(?string $arquivo): ?string
    {
        if (!$arquivo) {
            return null;
        }
        $caminho = $this->pastaUploads() . $arquivo;
        return 'uploads/' . $arquivo . (file_exists($caminho) ? '?v=' . filemtime($caminho) : '');
    }

    /**
     * Recebe uma foto enviada pelo formulário, confere pelos BYTES (nunca pelo nome nem pelo Content-Type), converte para WebP e
     * apaga a antiga. Sem arquivo novo, devolve o nome antigo. Reproduz o uploadImage do api.php.
     * @param array<string, mixed>|null $arquivo item do $_FILES
     * @throws ImagemInvalidaException com a mensagem que o admin vê
     */
    public function enviar(?array $arquivo, ?string $antigo = null): ?string
    {
        if ($arquivo === null || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $antigo;
        }
        if ($arquivo['size'] > self::TAMANHO_MAXIMO) {
            throw new ImagemInvalidaException('Imagem muito grande (máx 500KB)');
        }
        if (!function_exists('imagewebp')) {
            throw new ImagemInvalidaException('Servidor sem suporte a conversão de imagens (GD/WebP)');
        }
        $info = @getimagesize($arquivo['tmp_name']);
        if ($info === false) {
            throw new ImagemInvalidaException('Arquivo não é uma imagem válida');
        }
        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                $origem = @imagecreatefromjpeg($arquivo['tmp_name']);
                break;
            case IMAGETYPE_PNG:
                $origem = @imagecreatefrompng($arquivo['tmp_name']);
                break;
            case IMAGETYPE_GIF:
                $origem = @imagecreatefromgif($arquivo['tmp_name']);
                break;
            case IMAGETYPE_WEBP:
                $origem = @imagecreatefromwebp($arquivo['tmp_name']);
                break;
            default:
                throw new ImagemInvalidaException('Tipo de imagem não permitido (use JPG, PNG, GIF ou WEBP)');
        }
        if (!$origem) {
            throw new ImagemInvalidaException('Não foi possível processar a imagem');
        }
        imagepalettetotruecolor($origem);
        imagealphablending($origem, true);
        imagesavealpha($origem, true);

        $nome = 'vendedor_' . time() . '_' . bin2hex(random_bytes(4)) . '.webp';
        $ok = imagewebp($origem, $this->pastaUploads() . $nome, 85);
        // (sem imagedestroy: desde o PHP 8.0 a imagem é liberada sozinha, e a função é obsoleta no 8.5)
        if (!$ok) {
            throw new ImagemInvalidaException('Erro ao salvar imagem');
        }
        if ($antigo) {
            $velho = $this->pastaUploads() . $antigo;
            if (file_exists($velho)) {
                unlink($velho);
            }
        }
        return $nome;
    }

    /** Apaga a foto do disco (se existir). Não faz nada com nome vazio. */
    public function apagar(?string $arquivo): void
    {
        if (!$arquivo) {
            return;
        }
        $caminho = $this->pastaUploads() . $arquivo;
        if (file_exists($caminho)) {
            unlink($caminho);
        }
    }

    /** config app.uploads, ou a pasta uploads/ dentro da pasta pública do núcleo (a raiz do site, onde fica o index.php). */
    private function pastaUploads(): string
    {
        $definida = Config::obter('app.uploads');
        $pasta = is_string($definida) && $definida !== '' ? $definida : Config::publico() . '/uploads';
        return rtrim(str_replace('\\', '/', $pasta), '/') . '/';
    }
}
