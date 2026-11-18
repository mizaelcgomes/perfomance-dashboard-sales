<?php
declare(strict_types=1);

namespace App\Actions\Tipo;

use App\Core\Resultado;
use App\Repositories\TipoBeneficioRepository;
use App\Services\Texto;
use App\Services\TiposBeneficioService;
use PDOException;

/**
 * Caso de uso: criar ou editar um benefício personalizado (aba Metas & Regras → Tipos de Benefício). Cor e ícone só mudam quando vêm no envio
 * ('' = voltar ao automático); o liga/desliga antigo não os apaga. "ordem" (1 = primeiro) reposiciona o benefício na lista.
 */
final class SalvarTipoCustom
{
    public function __construct(private TipoBeneficioRepository $tipos, private TiposBeneficioService $servico)
    {
    }

    /** @param array<string, mixed> $d o envio inteiro (id, label, ativo, cor, icone, ordem): a presença de cor e icone importa */
    public function executar(array $d): Resultado
    {
        $id = (int) (is_scalar($d['id'] ?? 0) ? ($d['id'] ?? 0) : 0);
        $rotulo = trim(is_scalar($d['label'] ?? '') ? (string) ($d['label'] ?? '') : '');
        $ativo = isset($d['ativo']) ? (int) (bool) $d['ativo'] : 1;
        if (!$rotulo) {
            return Resultado::falha('Nome obrigatório');
        }
        if (Texto::tamanho($rotulo) > 100) {
            return Resultado::falha('O nome pode ter no máximo 100 caracteres');
        }
        $extras = [];
        if (array_key_exists('cor', $d)) {
            $cor = trim(is_scalar($d['cor']) ? (string) $d['cor'] : '');
            if ($cor !== '' && !TiposBeneficioService::corValida($cor)) {
                return Resultado::falha('Cor inválida (use o seletor de cor)');
            }
            $extras['cor'] = $cor === '' ? null : TiposBeneficioService::corValida($cor);
        }
        if (array_key_exists('icone', $d)) {
            $icone = trim(is_scalar($d['icone']) ? (string) $d['icone'] : '');
            if ($icone !== '' && !TiposBeneficioService::iconeValido($icone)) {
                return Resultado::falha('Ícone inválido');
            }
            $extras['icone'] = $icone === '' ? null : $icone;
        }
        $ordem = isset($d['ordem']) && $d['ordem'] !== '' ? (int) $d['ordem'] : 0;

        if ($id) {
            $chave = $this->tipos->chaveDoId($id);
            if (!$chave) {
                return Resultado::falha('Tipo não encontrado');
            }
            $this->tipos->atualizar($id, $rotulo, $ativo, $extras);
            if ($ordem > 0) {
                $this->servico->mover($chave, $ordem);
            }
            return Resultado::ok(['chave' => $chave], 'Tipo atualizado');
        }
        $chave = substr('custom_' . preg_replace('/[^a-z0-9_]/', '_', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $rotulo))), 0, 64);
        try {
            $this->tipos->inserir($chave, $rotulo, $ativo, $extras);
            if ($ordem > 0) {
                $this->servico->mover($chave, $ordem);
            }
            return Resultado::ok(['chave' => $chave], 'Tipo criado');
        } catch (PDOException $erro) {
            return Resultado::falha('Já existe um tipo com essa chave');
        }
    }
}
