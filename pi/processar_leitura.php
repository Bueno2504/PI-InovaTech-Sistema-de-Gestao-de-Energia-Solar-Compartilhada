<?php
// Prevenir qualquer output antes do JSON
ob_start();
session_start();

// Verificar login
if (!isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado']);
    exit;
}

// Pegar dados do usuário logado
$usuarioLogado = [
    'id' => $_SESSION['usuario_id'] ?? null,
    'nome' => $_SESSION['usuario_nome'] ?? '',
    'email' => $_SESSION['usuario_email'] ?? '',
    'perfil' => $_SESSION['usuario_perfil'] ?? ''
];

// Tratamento de erros para retornar JSON sempre
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    ob_clean();
    echo json_encode([
        'success' => false, 
        'message' => 'Erro PHP: ' . $errstr,
        'debug' => ['file' => $errfile, 'line' => $errline]
    ]);
    exit;
});

set_exception_handler(function($exception) {
    ob_clean();
    echo json_encode([
        'success' => false, 
        'message' => 'Exceção: ' . $exception->getMessage(),
        'debug' => ['file' => $exception->getFile(), 'line' => $exception->getLine()]
    ]);
    exit;
});

// Verificar se o arquivo de conexão existe
if (!file_exists('conexao.php')) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Arquivo conexao.php não encontrado']);
    exit;
}

require_once("conexao.php");

// Verificar se $pdo foi criado com sucesso
if (!isset($pdo) || $pdo === null) {
    ob_clean();
    echo json_encode([
        'success' => false, 
        'message' => 'Falha na conexão com banco de dados. Verifique se o MySQL está rodando.',
        'debug' => 'Variável $pdo não foi inicializada'
    ]);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];
$acao = $_GET['acao'] ?? '';

try {
    
    // ========================================
    // DETERMINAR ID DO ADMINISTRADOR
    // ========================================
    // Se o usuário for LEITOR, buscar o administrador responsável
    // Se for ADMIN, usar o próprio ID
    if ($usuarioLogado['perfil'] === 'LEITOR') {
        $stmt = $pdo->prepare("SELECT id_administrador_responsavel FROM usuario WHERE id_usuario = ?");
        $stmt->execute([$usuarioLogado['id']]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        $idAdministrador = $resultado['id_administrador_responsavel'] ?? null;
        
        if (!$idAdministrador) {
            ob_clean();
            echo json_encode([
                'success' => false, 
                'message' => 'Leitor não está vinculado a nenhum administrador. Entre em contato com o suporte.'
            ]);
            exit;
        }
    } else {
        $idAdministrador = $usuarioLogado['id'];
    }
    
    
    if ($metodo === 'GET') {
        
        // Listar condomínios do administrador responsável
        if ($acao === 'condominios') {
            $sql = "SELECT id_condominio as id, nome_condominio as nome, 
                           total_unidades, cnpj
                    FROM condominio 
                    WHERE id_administrador = ?
                    ORDER BY nome_condominio";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$idAdministrador]);
            ob_clean();
            echo json_encode([
                'success' => true, 
                'condominios' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'debug' => [
                    'id_administrador' => $idAdministrador,
                    'perfil_usuario' => $usuarioLogado['perfil']
                ]
            ]);
            exit;
        }
        
        // Listar unidades/residências de um condomínio
        elseif ($acao === 'unidades') {
            $cond_id = $_GET['condominio_id'] ?? 0;
            
            // Verificar se o condomínio pertence ao administrador
            $stmtCheck = $pdo->prepare("SELECT id_condominio FROM condominio WHERE id_condominio = ? AND id_administrador = ?");
            $stmtCheck->execute([$cond_id, $idAdministrador]);
            
            if (!$stmtCheck->fetch()) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Condomínio não autorizado']);
                exit;
            }
            
            $sql = "SELECT id_residencia as id, numero_residencia as numero 
                    FROM residencia 
                    WHERE id_condominio = ? AND ativa = 1 
                    ORDER BY numero_residencia";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$cond_id]);
            ob_clean();
            echo json_encode([
                'success' => true, 
                'unidades' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ]);
            exit;
        }
        
        // Obter última leitura de uma residência
        elseif ($acao === 'ultima_leitura') {
            $res_id = $_GET['residencia_id'] ?? 0;
            $sql = "SELECT valor_kwh, data_coleta, observacao
                    FROM leitura 
                    WHERE id_residencia = ?
                    ORDER BY data_coleta DESC
                    LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$res_id]);
            $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'leitura' => $resultado ?: null
            ]);
            exit;
        }
        
       // Histórico de leituras
elseif ($acao === 'historico') {
    $limite = isset($_GET['limite']) ? (int)$_GET['limite'] : 50; // CONVERSÃO PARA INT
    $cond_id = isset($_GET['condominio_id']) ? $_GET['condominio_id'] : '';
    $res_id = isset($_GET['residencia_id']) ? $_GET['residencia_id'] : '';
    
    $sql = "SELECT l.id_leitura, l.data_coleta, l.valor_kwh, l.observacao,
                   c.nome_condominio, r.numero_residencia,
                   u.nome as nome_leitor
            FROM leitura l
            INNER JOIN residencia r ON l.id_residencia = r.id_residencia
            INNER JOIN condominio c ON r.id_condominio = c.id_condominio
            INNER JOIN usuario u ON l.id_usuario = u.id_usuario
            WHERE c.id_administrador = ?";
    
    $params = [$idAdministrador];
    
    if (!empty($cond_id)) {
        $sql .= " AND r.id_condominio = ?";
        $params[] = $cond_id;
    }
    
    if (!empty($res_id)) {
        $sql .= " AND l.id_residencia = ?";
        $params[] = $res_id;
    }
    
    $sql .= " ORDER BY l.data_coleta DESC LIMIT " . $limite; 
    
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $historico = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_clean();
        echo json_encode([
            'success' => true, 
            'historico' => $historico,
            'total_registros' => count($historico),
            'debug' => [
                'limite' => $limite,
                'condominio_filtrado' => !empty($cond_id),
                'unidade_filtrada' => !empty($res_id)
            ]
        ]);
        exit;
        
    } catch (PDOException $e) {
        ob_clean();
        echo json_encode([
            'success' => false, 
            'message' => 'Erro na query: ' . $e->getMessage(),
            'sql' => $sql,
            'params' => $params
        ]);
        exit;
    }
}   
        
        // Estatísticas para dashboard
        elseif ($acao === 'estatisticas') {
            $hoje = date('Y-m-d');
            $mes_atual = date('Y-m');
            
            // Leituras hoje (do administrador)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM leitura l
                INNER JOIN residencia r ON l.id_residencia = r.id_residencia
                INNER JOIN condominio c ON r.id_condominio = c.id_condominio
                WHERE DATE(l.data_coleta) = ? AND c.id_administrador = ?
            ");
            $stmt->execute([$hoje, $idAdministrador]);
            $hoje_count = $stmt->fetch()['total'];
            
            // Leituras do mês
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM leitura l
                INNER JOIN residencia r ON l.id_residencia = r.id_residencia
                INNER JOIN condominio c ON r.id_condominio = c.id_condominio
                WHERE DATE_FORMAT(l.data_coleta, '%Y-%m') = ? AND c.id_administrador = ?
            ");
            $stmt->execute([$mes_atual, $idAdministrador]);
            $mes_count = $stmt->fetch()['total'];
            
            // Média de consumo do mês
            $stmt = $pdo->prepare("
                SELECT AVG(l.valor_kwh) as media 
                FROM leitura l
                INNER JOIN residencia r ON l.id_residencia = r.id_residencia
                INNER JOIN condominio c ON r.id_condominio = c.id_condominio
                WHERE DATE_FORMAT(l.data_coleta, '%Y-%m') = ? AND c.id_administrador = ?
            ");
            $stmt->execute([$mes_atual, $idAdministrador]);
            $media = $stmt->fetch()['media'] ?? 0;
            
            // Residências sem leitura no mês
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM residencia r
                INNER JOIN condominio c ON r.id_condominio = c.id_condominio
                WHERE r.ativa = 1 AND c.id_administrador = ?
                AND NOT EXISTS (
                    SELECT 1 FROM leitura l 
                    WHERE l.id_residencia = r.id_residencia
                    AND DATE_FORMAT(l.data_coleta, '%Y-%m') = ?
                )
            ");
            $stmt->execute([$idAdministrador, $mes_atual]);
            $pendentes = $stmt->fetch()['total'];
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'leituras_hoje' => (int)$hoje_count,
                'leituras_total' => (int)$mes_count,
                'media_consumo' => (float)round($media, 1),
                'pendentes' => (int)$pendentes
            ]);
            exit;
        }
        
        else {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Ação não reconhecida']);
            exit;
        }
    }
    
    // POST - Salvar nova leitura
    elseif ($metodo === 'POST') {
        
        $cond = trim($_POST['condominio'] ?? '');
        $res = trim($_POST['residencia'] ?? $_POST['unidade'] ?? '');
        $data = trim($_POST['data_leitura'] ?? '');
        $consumo = trim($_POST['consumo_casa'] ?? '');
        $obs = trim($_POST['observacoes'] ?? '');
        
        // Validações
        if (empty($cond) || empty($res) || empty($consumo) || empty($data)) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Campos obrigatórios faltando']);
            exit;
        }
        
        if (!is_numeric($consumo) || $consumo < 0) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Consumo inválido']);
            exit;
        }
        
        // Verificar se condomínio pertence ao administrador
        $stmt = $pdo->prepare("SELECT id_condominio FROM condominio WHERE id_condominio = ? AND id_administrador = ?");
        $stmt->execute([$cond, $idAdministrador]);
        if (!$stmt->fetch()) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Condomínio não autorizado']);
            exit;
        }
        
        // Verificar se residência pertence ao condomínio
        $stmt = $pdo->prepare("SELECT id_residencia FROM residencia WHERE id_residencia = ? AND id_condominio = ? AND ativa = 1");
        $stmt->execute([$res, $cond]);
        if (!$stmt->fetch()) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Residência não encontrada ou inativa']);
            exit;
        }
        
        $pdo->beginTransaction();
        
        try {
            // Inserir leitura
            $stmt = $pdo->prepare("INSERT INTO leitura (id_residencia, id_usuario, data_coleta, valor_kwh, observacao) 
                                   VALUES (?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $res,
                $usuarioLogado['id'],
                $data . ' ' . date('H:i:s'),
                $consumo,
                $obs
            ]);
            
            $leitura_id = $pdo->lastInsertId();
            
            $pdo->commit();
            
            ob_clean();
            echo json_encode([
                'success' => true, 
                'message' => 'Leitura salva com sucesso!', 
                'leitura_id' => $leitura_id,
                'data_processamento' => date('Y-m-d H:i:s')
            ]);
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Erro ao salvar: ' . $e->getMessage()]);
            exit;
        }
    }
    
    // PUT - Atualizar capacidade de geração da usina
    elseif ($metodo === 'PUT') {
        parse_str(file_get_contents("php://input"), $_PUT);
        
        $condominio_id = $_PUT['condominio_id'] ?? '';
        $capacidade = $_PUT['capacidade_geracao_kwh'] ?? '';
        
        if (empty($condominio_id) || empty($capacidade)) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
            exit;
        }
        
        if (!is_numeric($capacidade) || $capacidade < 0) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Capacidade inválida']);
            exit;
        }
        
        // Verificar se condomínio pertence ao administrador
        $stmt = $pdo->prepare("SELECT id_condominio FROM condominio WHERE id_condominio = ? AND id_administrador = ?");
        $stmt->execute([$condominio_id, $idAdministrador]);
        if (!$stmt->fetch()) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Condomínio não autorizado']);
            exit;
        }
        
        $pdo->beginTransaction();
        
        try {
            // Verificar se já existe usina para este condomínio
            $stmt = $pdo->prepare("SELECT id_usina FROM usina WHERE id_condominio = ?");
            $stmt->execute([$condominio_id]);
            $usina_existente = $stmt->fetch();
            
            if ($usina_existente) {
                // Atualizar usina existente
                $stmt = $pdo->prepare("UPDATE usina SET capacidade_geracao_kwh = ? WHERE id_condominio = ?");
                $stmt->execute([$capacidade, $condominio_id]);
                $mensagem = 'Capacidade de geração atualizada com sucesso!';
            } else {
                // Criar nova usina
                $stmt = $pdo->prepare("INSERT INTO usina (id_condominio, capacidade_geracao_kwh, data_instalacao, ativa) 
                                       VALUES (?, ?, CURDATE(), 1)");
                $stmt->execute([$condominio_id, $capacidade]);
                $mensagem = 'Usina cadastrada com sucesso!';
            }
            
            $pdo->commit();
            
            ob_clean();
            echo json_encode([
                'success' => true, 
                'message' => $mensagem,
                'capacidade' => (float)$capacidade
            ]);
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Erro ao atualizar: ' . $e->getMessage()]);
            exit;
        }
    }
    
    else {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Método não permitido']);
        exit;
    }
    
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
    exit;
}
?>
