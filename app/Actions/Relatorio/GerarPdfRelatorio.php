<?php
declare(strict_types=1);

namespace App\Actions\Relatorio;

use App\Core\Resultado;
use App\Services\RelatorioClosersPdf;
use App\Services\RelatorioService;

/**
 * Caso de uso: gerar o PDF da "Performance de Closers" de um mês. Baixa a tabela COMPLETA do mês (todas as semanas e benefícios);
 * só o filtro de equipe vale ('' = todas, '__sem' = closers sem equipe, ou o nome da equipe).
 * Devolve um Resultado com 'pdf' (os bytes) e 'nome' (o nome sugerido para o arquivo).
 */
final class GerarPdfRelatorio
{
    private const MARCA = 'TOP CLOSERS';

    public function __construct(private RelatorioService $relatorio, private RelatorioClosersPdf $pdf)
    {
    }

    public function executar(int $ano, int $mes, string $equipe = ''): Resultado
    {
        $dados = $this->relatorio->closers($ano, $mes);
        $bytes = $this->pdf->gerar($dados, trim($equipe), self::MARCA);
        return Resultado::ok([
            'pdf' => $bytes,
            'nome' => sprintf('relatorio-closers-%04d-%02d.pdf', $dados['ano'], $dados['mes']),
        ]);
    }
}
