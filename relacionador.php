<?php
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

ini_set('memory_limit', '-1'); // Desabilita limite de memória
set_time_limit(0);             // Desabilita limite de tempo

/**
 * Lê uma planilha (XLSX ou CSV) e retorna um array bidimensional,
 * sem descartar linhas consideradas "vazias".
 *
 * @param string $filename  Caminho do arquivo
 * @param bool   $isCsv     Indica se é CSV
 * @param string $delimiter Delimitador CSV
 * @return array
 */
function lerPlanilha($filename, $isCsv = false, $delimiter = ',')
{
    if ($isCsv) {
        $reader = new Csv();
        $reader->setDelimiter($delimiter);
        $reader->setContiguous(true); // Força a leitura de todas as linhas
    } else {
        $reader = new Xlsx();
    }

    $spreadsheet = $reader->load($filename);
    $sheet = $spreadsheet->getActiveSheet();

    $data = [];
    foreach ($sheet->getRowIterator() as $row) {
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);

        $rowData = [];
        foreach ($cellIterator as $cell) {
            $rowData[] = $cell->getValue();
        }
        // Adiciona todas as linhas, mesmo que algumas células estejam vazias
        $data[] = $rowData;
    }
    return $data;
}

// ----------------------------------------------------------
// 1) Ler a planilha de e-mails válidos
// ----------------------------------------------------------
echo "Lendo planilha de válidos...\n";
$planilhaValidos = lerPlanilha('Planilha-valida.csv', true, ';');
echo "Linhas lidas (planilhaValidos): " . count($planilhaValidos) . "\n";

// Remove o cabeçalho, se houver
array_shift($planilhaValidos);
echo "Linhas após remover cabeçalho (planilhaValidos): " . count($planilhaValidos) . "\n";

$validos = [];
foreach ($planilhaValidos as $linhaValida) {
    if (!isset($linhaValida[0])) continue;
    $emailValido = strtolower(trim($linhaValida[0]));
    if ($emailValido !== '') {
        $validos[$emailValido] = true;
    }
}
echo "Total de e-mails válidos (unique): " . count($validos) . "\n";

// ----------------------------------------------------------
// 2) Ler a planilha completa (com nomes e vários e-mails)
// ----------------------------------------------------------
echo "Lendo planilha com nomes...\n";
$planilha2 = lerPlanilha('pros.csv', true, ';');
echo "Linhas lidas (planilha2): " . count($planilha2) . "\n";

// Remove o cabeçalho, se houver
array_shift($planilha2);
echo "Linhas após remover cabeçalho (planilha2): " . count($planilha2) . "\n";

// ----------------------------------------------------------
// DEBUG: Veja como está uma linha qualquer da planilha de nomes
// Descomente para ver a linha 2 (índice 1), por exemplo
//
// var_dump($planilha2[1]);
// exit;
//
// Assim, você verá onde realmente estão os e-mails (ex.: índice 42, 43...).
// ----------------------------------------------------------


/**
 * Mapeamento dos dados:
 * Ajuste conforme a estrutura real da sua planilha.
 * Exemplo:
 *  - 'nomeIndex' = 2  -> RAZAO_SOCIAL
 *  - 'emailIndexes' = [42..46] -> EMAIL_01..EMAIL_05
 */
$mapeamento = [
    // Empresa
    [
        'nomeIndex'    => 2,                   // RAZAO_SOCIAL
        'emailIndexes' => [42, 43, 44, 45, 46]  // EMAIL_01..EMAIL_05
    ],
    // Sócio 1
    [
        'nomeIndex'    => 60,                  // NOME_SOCIO_01
        'emailIndexes' => [95, 96, 97, 98, 99]  // EMAIL_01_SOCIO_01..EMAIL_05_SOCIO_01
    ],
    // Sócio 2
    [
        'nomeIndex'    => 101,                 // NOME_SOCIO_02
        'emailIndexes' => [136, 137, 138, 139, 140] // EMAIL_01_SOCIO_02..EMAIL_05_SOCIO_02
    ],
    // Sócio 3
    [
        'nomeIndex'    => 142,                 // NOME_SOCIO_03
        'emailIndexes' => [177, 178, 179, 180, 181]
    ],
    // Sócio 4
    [
        'nomeIndex'    => 183,                 // NOME_SOCIO_04
        'emailIndexes' => [218, 219, 220, 221, 222]
    ],
    // Sócio 5
    [
        'nomeIndex'    => 224,                 // NOME_SOCIO_05
        'emailIndexes' => [259, 260, 261, 262, 263]
    ],
];


// ----------------------------------------------------------
// 3) Montar a lista final unindo e-mails e nome,
//    considerando somente os e-mails que estão em $validos.
// ----------------------------------------------------------
$listaEmails = [];
$debugCount = 0; // para contar quantos e-mails foram encontrados

foreach ($planilha2 as $linhaIndex => $linha) {
    // Se quiser debugar cada linha, pode fazer algo como:
    // if ($linhaIndex < 3) { var_dump($linha); }

    foreach ($mapeamento as $config) {
        // Lê o nome
        $nome = isset($linha[$config['nomeIndex']]) ? trim($linha[$config['nomeIndex']]) : '';

        // Percorre as colunas de e-mail
        foreach ($config['emailIndexes'] as $colIndex) {
            if (isset($linha[$colIndex])) {
                $email = strtolower(trim($linha[$colIndex]));
                // Só adiciona se o e-mail não estiver vazio e estiver na lista de válidos
                if ($email !== '' && isset($validos[$email])) {
                    echo "Encontrado: $email | Nome: $nome\n";
                    $debugCount++;
                    $listaEmails[] = [
                        'email' => $email,
                        'nome'  => $nome
                    ];
                }
            }
        }
    }
}

echo "Total de e-mails encontrados na planilha 2 (que estão em validos): $debugCount\n";

// ----------------------------------------------------------
// 4) Remover duplicatas (opcional)
// ----------------------------------------------------------
$listaUnica = [];
foreach ($listaEmails as $item) {
    $chave = $item['email'] . '|' . $item['nome'];
    $listaUnica[$chave] = $item;
}
$listaUnica = array_values($listaUnica);

echo "Total de e-mails após remover duplicatas: " . count($listaUnica) . "\n";

// ----------------------------------------------------------
// 5) Exportar os resultados para um arquivo CSV
//    com 2 colunas: "email" e "nome"
// ----------------------------------------------------------
$arquivoExport = 'registros_encontrados.csv';
$output = fopen($arquivoExport, 'w');

// Cabeçalho fixo
fputcsv($output, ['email', 'nome'], ';');

// Escreve cada registro
foreach ($listaUnica as $registro) {
    fputcsv($output, [$registro['email'], $registro['nome']], ';');
}

fclose($output);
echo "Exportado com sucesso para: {$arquivoExport}\n";
exit;
