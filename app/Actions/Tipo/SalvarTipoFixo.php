<?php
declare(strict_types=1);

namespace App\Actions\Tipo;

use App\Core\Resultado;
use App\Services\TiposBeneficioService;

/** Caso de uso: editar um benefício padrão (fixo). O nome não muda, mas a cor, o ícone e a posição sim. */
final class SalvarTipoFixo
{
    public function __construct(private TiposBeneficioService $servico)
    {
    }

    /** @param array<string, mixed> $d o envio inteiro (chave, cor, icone, ordem): a presença de cor e icone importa */
    public function executar(array $d): Resultado
    {
        $chave = is_scalar($d['chave'] ?? '') ? (string) ($d['chave'] ?? '') : '';
        if (!isset(TiposBeneficioService::FIXOS[$chave])) {
            return Resultado::falha('Benefício não encontrado');
        }
        $estilo = $this->servico->estiloDosFixos();
        $atual = $estilo[$chave] ?? [];
        if (array_key_exists('cor', $d)) {
            $cor = trim(is_scalar($d['cor']) ? (string) $d['cor'] : '');
            if ($cor !== '' && !TiposBeneficioService::corValida($cor)) {
                return Resultado::falha('Cor inválida (use o seletor de cor)');
            }
            if ($cor === '') {
                unset($atual['cor']);
            } else {
                $atual['cor'] = TiposBeneficioService::corValida($cor);
            }
        }
        if (array_key_exists('icone', $d)) {
            $icone = trim(is_scalar($d['icone']) ? (string) $d['icone'] : '');
            if ($icone !== '' && !TiposBeneficioService::iconeValido($icone)) {
                return Resultado::falha('Ícone inválido');
            }
            if ($icone === '') {
                unset($atual['icone']);
            } else {
                $atual['icone'] = $icone;
            }
        }
        if ($atual) {
            $estilo[$chave] = $atual;
        } else {
            unset($estilo[$chave]);
        }
        $this->servico->gravarEstiloDosFixos($estilo);
        $ordem = isset($d['ordem']) && $d['ordem'] !== '' ? (int) $d['ordem'] : 0;
        if ($ordem > 0 && $chave !== 'outros') {
            $this->servico->mover($chave, $ordem);
        }
        return Resultado::ok(['chave' => $chave], 'Benefício atualizado');
    }
}
