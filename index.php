<?php
/************************************************
 * Aumenta ou remove o limite de execução
 ************************************************/
set_time_limit(0); // Permite tempo de execução ilimitado (ou aumente para 300, 600 etc.)

/************************************************
 * Carrega o autoload do Composer
 ************************************************/
require 'vendor/autoload.php';

use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;

/************************************************
 * CONFIGURAÇÃO DE CONEXÃO COM O BANCO (PDO)
 ************************************************/
$host = 'localhost';      
$db   = 'validate_emails';
$user = 'root';
$pass = 'admin';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}

/************************************************
 * E-MAIL QUE USAREMOS COMO REMETENTE NO TESTE SMTP
 ************************************************/
$fromEmail = "eric.silva@pointcondominio.com.br"; 

/************************************************
 * LISTA BÁSICA DE E-MAILS "ROLE-BASED"
 ************************************************/
$roleBasedList = [
    'admin', 'administrator', 'postmaster', 'hostmaster', 'webmaster',
    'info', 'help', 'billing', 'contact', 'sales', 'support', 'suporte', 'op'
];

/************************************************
 * VERIFICAÇÃO SMTP BÁSICA
 ************************************************/
function verificarSMTP($from, $checkEmail, $domain)
{
    $mxRecords = [];
    if (!getmxrr($domain, $mxRecords)) {
        return false;
    }

    $smtpServer = $mxRecords[0];
    $port = 25;
    $timeout = 10;
    $errno = 0;
    $errstr = '';

    $socket = @fsockopen($smtpServer, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        return false;
    }

    fgets($socket, 1024);

    fputs($socket, "HELO $domain\r\n");
    fgets($socket, 1024);

    fputs($socket, "MAIL FROM: <$from>\r\n");
    fgets($socket, 1024);

    fputs($socket, "RCPT TO: <$checkEmail>\r\n");
    $response = fgets($socket, 1024);

    fputs($socket, "QUIT\r\n");
    fclose($socket);

    $code = substr(trim($response), 0, 3);
    return in_array($code, ['250','251']);
}

/************************************************
 * DETECÇÃO DE DOMÍNIO "CATCH-ALL"
 ************************************************/
function isCatchAllDomain($domain, $from)
{
    $randomLocalPart = 'invalid_test_' . uniqid();
    $randomEmail = $randomLocalPart . '@' . $domain;

    $validCheck = verificarSMTP($from, "test@$domain", $domain);
    $invalidCheck = verificarSMTP($from, $randomEmail, $domain);

    return ($validCheck && $invalidCheck);
}

/************************************************
 * OBTÉM A DATA DE CRIAÇÃO DO DOMÍNIO VIA WHOIS
 ************************************************/
function getDomainCreationDate($domain)
{
    $whoisServer = 'whois.verisign-grs.com';

    $conn = @fsockopen($whoisServer, 43);
    if (!$conn) {
        return null;
    }

    fputs($conn, "$domain\r\n");
    $response = '';
    while (!feof($conn)) {
        $response .= fgets($conn, 128);
    }
    fclose($conn);

    if (preg_match('/Creation Date:\s*(\d{4}-\d{2}-\d{2})/i', $response, $matches)) {
        return $matches[1];
    }

    return null;
}

/************************************************
 * FUNÇÃO AUXILIAR PARA TRATAR CNPJ
 ************************************************/
function formatarCNPJ($cnpj)
{
    // Se vier em notação científica, converte vírgula em ponto e depois faz a conversão
    if (stripos($cnpj, 'E') !== false) {
        // Troca a vírgula por ponto
        $cnpj = str_replace(",", ".", $cnpj);

        // Converte para float e depois formata como inteiro
        $cnpjFloat = (float)$cnpj;
        $cnpj = sprintf("%.0f", $cnpjFloat);
    }

    // Remove tudo que não for dígito
    return preg_replace('/\D/', '', $cnpj);
}

/************************************************
 * FUNÇÃO PRINCIPAL: VALIDAÇÃO DETALHADA DE E-MAIL
 * Agora com cache para evitar múltiplas consultas
 ************************************************/
function validarEmailDetalhado($email, $from, $roleBasedList)
{
    // Array de resultado inicial
    $result = [
        'email'                => $email,
        'syntax_valid'         => false,
        'dns_mx_valid'         => false,
        'smtp_check'           => false,
        'role_based'           => false,
        'catch_all'            => false,
        'domain_creation_date' => null,
        'domain_age_days'      => null,
    ];

    // 1. Validação de sintaxe (Egulias)
    $validator = new EmailValidator();
    if (!$validator->isValid($email, new RFCValidation())) {
        return $result; // Sintaxe inválida, encerra
    }
    $result['syntax_valid'] = true;

    // Extrai o domínio do e-mail
    $domain = substr(strrchr($email, "@"), 1);

    // Cria um cache estático para domínios
    static $domainCache = [];

    if (isset($domainCache[$domain])) {
        // Reutiliza os dados do cache para o domínio
        $cached = $domainCache[$domain];
        $result['dns_mx_valid']         = $cached['dns_mx_valid'];
        $result['smtp_check']           = $cached['smtp_check'];
        $result['catch_all']            = $cached['catch_all'];
        $result['domain_creation_date'] = $cached['domain_creation_date'];
        $result['domain_age_days']      = $cached['domain_age_days'];
    } else {
        // 2. Verificação DNS MX
        if (!checkdnsrr($domain, 'MX')) {
            // Armazena no cache que o domínio falhou no DNS MX
            $domainCache[$domain] = [
                'dns_mx_valid'         => false,
                'smtp_check'           => false,
                'catch_all'            => false,
                'domain_creation_date' => null,
                'domain_age_days'      => null,
            ];
            return $result; // DNS MX não encontrado
        }
        $result['dns_mx_valid'] = true;

        // 3. Verificação via SMTP (apenas uma vez por domínio)
        // Observação: se quiser ser ainda mais rápido, você pode testar com um e-mail "genérico" do domínio
        // em vez de cada e-mail real. Assim, a verificação seria ainda menos repetitiva.
        if (verificarSMTP($from, $email, $domain)) {
            $result['smtp_check'] = true;
        }

        // 4. Detecção de catch-all
        $result['catch_all'] = isCatchAllDomain($domain, $from);

        // 5. Data de criação do domínio (WHOIS)
        $creationDate = getDomainCreationDate($domain);
        if ($creationDate) {
            $result['domain_creation_date'] = $creationDate;
            $creationTimestamp = strtotime($creationDate);
            if ($creationTimestamp) {
                $diffDays = (time() - $creationTimestamp) / (60 * 60 * 24);
                $result['domain_age_days'] = (int) round($diffDays);
            }
        }

        // Armazena os resultados do domínio no cache
        $domainCache[$domain] = [
            'dns_mx_valid'         => $result['dns_mx_valid'],
            'smtp_check'           => $result['smtp_check'],
            'catch_all'            => $result['catch_all'],
            'domain_creation_date' => $result['domain_creation_date'],
            'domain_age_days'      => $result['domain_age_days'],
        ];
    }

    // 6. Detecção de e-mail "role-based"
    $localPart = strtolower(explode('@', $email)[0]);
    if (in_array($localPart, $roleBasedList)) {
        $result['role_based'] = true;
    }

    return $result;
}

/************************************************
 * TRATAMENTO DO FORMULÁRIO DE INSERÇÃO MANUAL
 ************************************************/
if (isset($_POST['submit_single'])) {
    $cnpj    = $_POST['cnpj']    ?? '';
    $email01 = $_POST['email_01'] ?? '';

    if (!empty($cnpj) && !empty($email01)) {
        $cnpj = formatarCNPJ($cnpj);

        // Evita duplicar e-mails já existentes
        if (!existeEmail($pdo, $email01)) {
            $resultado = validarEmailDetalhado($email01, $fromEmail, $roleBasedList);

            $isValid   = ($resultado['syntax_valid'] && $resultado['dns_mx_valid'] && $resultado['smtp_check']) ? 1 : 0;
            $roleBased = $resultado['role_based'] ? 1 : 0;
            $catchAll  = $resultado['catch_all']  ? 1 : 0;
            $domainCreationDate = $resultado['domain_creation_date'];
            $domainAgeDays      = $resultado['domain_age_days'];

            $sql = "INSERT INTO emails 
                    (cnpj, email_01, is_valid, role_based, catch_all, domain_creation_date, domain_age_days)
                    VALUES
                    (:cnpj, :email_01, :is_valid, :role_based, :catch_all, :domain_creation_date, :domain_age_days)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':cnpj', $cnpj);
            $stmt->bindParam(':email_01', $email01);
            $stmt->bindParam(':is_valid', $isValid, PDO::PARAM_INT);
            $stmt->bindParam(':role_based', $roleBased, PDO::PARAM_INT);
            $stmt->bindParam(':catch_all', $catchAll, PDO::PARAM_INT);
            $stmt->bindParam(':domain_creation_date', $domainCreationDate);
            $stmt->bindParam(':domain_age_days', $domainAgeDays, PDO::PARAM_INT);

            if ($stmt->execute()) {
                echo "<p style='color:green;'>Registro inserido com sucesso!</p>";
            } else {
                echo "<p style='color:red;'>Erro ao inserir registro.</p>";
            }
        } else {
            echo "<p style='color:orange;'>E-mail já existe no banco e não foi inserido novamente.</p>";
        }
    } else {
        echo "<p style='color:red;'>Preencha todos os campos!</p>";
    }
}

/************************************************
 * TRATAMENTO DO UPLOAD DE ARQUIVO CSV
 ************************************************/
if (isset($_POST['submit_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $tmpName = $_FILES['csv_file']['tmp_name'];

        // Ajuste conforme seu CSV ("," ou ";")
        if (($handle = fopen($tmpName, "r")) !== false) {
            $linhasImportadas = 0;

            // Descomente se tiver cabeçalho:
            // fgetcsv($handle, 1000, ";");

            // Defina o tamanho do lote
            $batchSize = 2000;
            $countLote = 0;

            while (($data = fgetcsv($handle, 1000, ";")) !== false) {
                // Se quiser parar depois de 2.000 linhas, use:
                if ($countLote >= $batchSize) {
                    // Você pode exibir uma mensagem ou simplesmente parar
                    echo "<p style='color:blue;'>Limite de {$batchSize} linhas atingido. Rode novamente se quiser continuar.</p>";
                    break;
                }

                // Esperamos apenas 2 colunas: [CNPJ, EMAIL_01]
                $cnpjCsv  = $data[0] ?? '';
                $emailCsv = $data[1] ?? '';

                if (!empty($cnpjCsv) && !empty($emailCsv)) {
                    $cnpjCsv = formatarCNPJ($cnpjCsv);

                    // Verifica se já existe no banco
                    if (existeEmail($pdo, $emailCsv)) {
                        // Se já existe, pula
                        continue;
                    }

                    // Valida o e-mail
                    $resultado = validarEmailDetalhado($emailCsv, $fromEmail, $roleBasedList);

                    $isValid   = ($resultado['syntax_valid'] && $resultado['dns_mx_valid'] && $resultado['smtp_check']) ? 1 : 0;
                    $roleBased = $resultado['role_based'] ? 1 : 0;
                    $catchAll  = $resultado['catch_all']  ? 1 : 0;
                    $domainCreationDate = $resultado['domain_creation_date'];
                    $domainAgeDays      = $resultado['domain_age_days'];

                    // INSERT no banco
                    $sql = "INSERT INTO emails 
                            (cnpj, email_01, is_valid, role_based, catch_all, domain_creation_date, domain_age_days)
                            VALUES
                            (:cnpj, :email_01, :is_valid, :role_based, :catch_all, :domain_creation_date, :domain_age_days)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindParam(':cnpj', $cnpjCsv);
                    $stmt->bindParam(':email_01', $emailCsv);
                    $stmt->bindParam(':is_valid', $isValid, PDO::PARAM_INT);
                    $stmt->bindParam(':role_based', $roleBased, PDO::PARAM_INT);
                    $stmt->bindParam(':catch_all', $catchAll, PDO::PARAM_INT);
                    $stmt->bindParam(':domain_creation_date', $domainCreationDate);
                    $stmt->bindParam(':domain_age_days', $domainAgeDays, PDO::PARAM_INT);

                    if ($stmt->execute()) {
                        $linhasImportadas++;
                    }
                }

                $countLote++;
            }
            fclose($handle);
            echo "<p style='color:green;'>$linhasImportadas registros importados com sucesso neste lote!</p>";
        } else {
            echo "<p style='color:red;'>Não foi possível abrir o arquivo CSV.</p>";
        }
    } else {
        echo "<p style='color:red;'>Nenhum arquivo CSV foi selecionado.</p>";
    }
}

/************************************************
 * FUNÇÃO QUE VERIFICA SE O E-MAIL JÁ EXISTE
 ************************************************/
function existeEmail(PDO $pdo, $email)
{
    $sqlCheck = "SELECT COUNT(*) FROM emails WHERE email_01 = :email";
    $stmtCheck = $pdo->prepare($sqlCheck);
    $stmtCheck->bindParam(':email', $email);
    $stmtCheck->execute();
    $alreadyExists = $stmtCheck->fetchColumn();
    return ($alreadyExists > 0);
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Cadastro e Consulta de E-mails</title>
</head>
<body>
    <h1>Inserir E-mail Manualmente</h1>
    <form action="" method="post">
        <label for="cnpj">CNPJ:</label>
        <input type="text" name="cnpj" id="cnpj" required>
        <br><br>
        <label for="email_01">E-mail:</label>
        <input type="text" name="email_01" id="email_01" required>
        <br><br>
        <input type="submit" name="submit_single" value="Salvar">
    </form>

    <hr>

    <h1>Importar CSV</h1>
    <form action="" method="post" enctype="multipart/form-data">
        <label for="csv_file">Selecione o arquivo CSV:</label>
        <input type="file" name="csv_file" id="csv_file" accept=".csv">
        <br><br>
        <input type="submit" name="submit_csv" value="Importar CSV">
    </form>

    <hr>

    <h1>Consulta de E-mails</h1>
    <form method="get" action="">
        <label for="consulta_email">Pesquisar por e-mail:</label>
        <input type="text" name="consulta_email" id="consulta_email" placeholder="Digite parte do e-mail">
        <br><br>
        <input type="submit" name="action" value="Pesquisar">
        <input type="submit" name="action" value="Mostrar Válidos">
        <input type="submit" name="action" value="Mostrar Inválidos">
        <input type="submit" name="action" value="Mostrar Todos">
    </form>

    <br>
    <?php
    /************************************************
     * PROCESSA A CONSULTA DE E-MAILS (via GET)
     ************************************************/
    if (isset($_GET['action'])) {
        $action = $_GET['action'];
        $query = "";
        $params = [];

        if ($action == "Pesquisar") {
            $emailSearch = $_GET['consulta_email'] ?? '';
            $query = "SELECT * FROM emails WHERE email_01 LIKE :search";
            $params[':search'] = "%{$emailSearch}%";
        } elseif ($action == "Mostrar Válidos") {
            $query = "SELECT * FROM emails WHERE is_valid = 1";
        } elseif ($action == "Mostrar Inválidos") {
            $query = "SELECT * FROM emails WHERE is_valid = 0";
        } elseif ($action == "Mostrar Todos") {
            $query = "SELECT * FROM emails";
        }

        if (!empty($query)) {
            $stmt = $pdo->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($results) {
                echo "<h2>Resultados da Consulta:</h2>";
                echo "<table border='1' cellpadding='5'>";
                echo "<tr>
                        <th>ID</th>
                        <th>CNPJ</th>
                        <th>E-mail</th>
                        <th>Válido</th>
                        <th>Role-based</th>
                        <th>Catch-all</th>
                        <th>Criação do Domínio</th>
                        <th>Idade (dias)</th>
                      </tr>";
                foreach ($results as $row) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['cnpj']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['email_01']) . "</td>";
                    echo "<td>" . ($row['is_valid'] ? 'Sim' : 'Não') . "</td>";
                    echo "<td>" . ($row['role_based'] ? 'Sim' : 'Não') . "</td>";
                    echo "<td>" . ($row['catch_all'] ? 'Sim' : 'Não') . "</td>";
                    echo "<td>" . htmlspecialchars($row['domain_creation_date']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['domain_age_days']) . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "<p>Nenhum registro encontrado.</p>";
            }
        }
    }
    ?>
</body>
</html>
