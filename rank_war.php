<?php
    // -------------------------------------------------------------------------
    // 1) TIMEZONE (CRÍTICO)
    // Ajuste para o mesmo fuso do servidor do jogo / sua regra de horários.
    // Se você não ajustar e o servidor estiver em UTC, a troca vai ficar errada.
    // -------------------------------------------------------------------------
    date_default_timezone_set('America/Sao_Paulo');

    $agora = new DateTime('now');
    $diaSemana = (int)$agora->format('N'); // 1=Seg ... 6=Sáb ... 7=Dom
    $horaMin = $agora->format('H:i');

    // -------------------------------------------------------------------------
    // 2) TÍTULO POR JANELA (Sáb 18:00 -> Seg 18:00 = GW)
    // -------------------------------------------------------------------------
    $horaTrocaTitulo = '18:00';
    $emGuildWar = false;

    if ($diaSemana === 6) { // Sábado
        $emGuildWar = ($horaMin >= $horaTrocaTitulo);
    } elseif ($diaSemana === 7) { // Domingo
        $emGuildWar = true;
    } elseif ($diaSemana === 1) { // Segunda
        $emGuildWar = ($horaMin <= $horaTrocaTitulo); // Mostra GW até 18:00; às 18:01 já é City War
    } else {
        $emGuildWar = false; // Terça a Sexta: City War
    }

    $torneioNome = $emGuildWar ? "Guild War Tournament" : "City War Tournament";

    // -------------------------------------------------------------------------
    // 3) EVENTO "AO VIVO" (efeito)
    // GW: Sáb/Dom 19:00-20:00
    // City: Seg-Sex 20:00-20:30
    // -------------------------------------------------------------------------
    $eventoAoVivo = false;

    if (($diaSemana === 6 || $diaSemana === 7) && ($horaMin >= '19:00' && $horaMin <= '20:00')) {
        $eventoAoVivo = true;
    }
    if (($diaSemana >= 1 && $diaSemana <= 5) && ($horaMin >= '20:00' && $horaMin <= '20:30')) {
        $eventoAoVivo = true;
    }

    // -------------------------------------------------------------------------
    // 4) CONTROLE GLOBAL (arquivo) para não mostrar pontuação velha
    //
    // A regra:
    // - Em cada "período" (GW ou City), definimos um baseline (hash do TOP10)
    // - Enquanto o TOP10 não mudar vs baseline, mostramos "Aguardando..."
    //
    // Isso é GLOBAL (não depende do usuário), então usamos arquivo e não session.
    // -------------------------------------------------------------------------
    $anoIso = $agora->format('o');
    $semanaIso = $agora->format('W');
    $periodoKey = $anoIso . '-W' . $semanaIso . ($emGuildWar ? '-G' : '-C');

    // Ajuste o caminho para um local GRAVÁVEL pelo PHP:
    // Ideal: uma pasta cache dentro do seu site (ex.: /var/www/html/cache/ no Linux),
    // aqui deixei um exemplo no Windows.
    $stateFile = __DIR__ . DIRECTORY_SEPARATOR . 'war_rank_state.json';

    $state = [
        'periodoKey'     => null,
        'baselineHash'   => null,
        'baselineSetAt'  => null,
    ];

    if (is_file($stateFile)) {
        $raw  = @file_get_contents($stateFile);
        $json = $raw ? json_decode($raw, true) : null;
        if (is_array($json)) {
            $state = array_merge($state, $json);
        }
    }
    ?>

    <h2>
        <?php if ($eventoAoVivo): ?>
            <span class="live-badge">
                <span class="live-dot"></span>
                LIVE
            </span>
            ⚔
        <?php endif; ?>
        <?= htmlspecialchars($torneioNome) ?>
    </h2>

    <table>
        <thead>
            <tr>
                <th>Rank</th>
                <th>Points</th>
                <th>Guild Name</th>
            </tr>
        </thead>
        <tbody>

    <?php

        // Arquivo de segurança externo
        require_once 'C:\\AppServ\\_seguranca\\db_config.php';



        try {

            // Conexão usando as variáveis do seu db_config.php
            $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Busca as pontuações atuais
            $sql = "SELECT Name, Points FROM cms_point_guilds ORDER BY Points DESC LIMIT 10";
            $stmt = $pdo->query($sql);
            $guilds = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Hash do TOP10 atual
            $assinaturaAtualData = [];
            if ($guilds) {
                foreach ($guilds as $g) {
                    $assinaturaAtualData[] = [
                        'Name'   => (string)$g['Name'],
                        'Points' => (string)$g['Points'],
                    ];
                }
            }
            $assinaturaAtual = hash('sha256', json_encode($assinaturaAtualData, JSON_UNESCAPED_UNICODE));

            // Se mudou o período (GW<->City), reseta baseline para o estado atual
            // Assim, o site só volta a exibir quando o banco mudar de verdade.
            if ($state['periodoKey'] !== $periodoKey) {
                $state['periodoKey']    = $periodoKey;
                $state['baselineHash']  = $assinaturaAtual;
                $state['baselineSetAt'] = $agora->format(DateTime::ATOM);
                @file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }

            $baselineHash = $state['baselineHash'];

            // Se o hash atual ainda é igual ao baseline => ainda não mudou => aguardar
            $aindaNaoMudou = ($baselineHash !== null && $assinaturaAtual === $baselineHash);

            // Regra final de exibição:
            // - Se o evento está AO VIVO: pode exibir (se tiver dados)
            // - Se não está AO VIVO: só exibe se já mudou vs baseline
            //   (senão, mostra "Aguardando..." e não exibe pontos velhos)
            $podeExibirRanking = false;

            if ($eventoAoVivo) {
                $podeExibirRanking = true;
            } else {
                $podeExibirRanking = !$aindaNaoMudou;
            }

            if (!$podeExibirRanking || !$guilds) {
                if ($emGuildWar) {
                    echo "Aguardando pontuações do torneio da Guild War…";
                } else {
                    echo "Aguardando pontuações do torneio da City War…";
                }
            } else {
                foreach($guilds as $i => $guild) {
                    $pos = $i + 1;
                    $classPos = ($pos <= 3) ? "pos-$pos" : "";
                    echo "
                    <tr class=\"$classPos\">
                        <td>$pos.</td>
                        <td>{$guild['Points']}</td>
                        <td>{$guild['Name']}</td>
                    </tr>";
                }
            }

        } catch (PDOException $e) {

            echo "Erro de conexão com o Banco de Dados.";

        }

        ?>

        </tbody>
    </table>
