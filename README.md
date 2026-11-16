# Relatórios G4F ANA 2.0.5

Fork independente do G4F Reports 1.8.19 para o Contrato ANA 006/2025, compatível com GLPI 11 e 12, com revisão direcionada ao GLPI 12.0.1. O diretório, as classes, as configurações e os rascunhos são próprios do plugin `g4freportsana`. A instalação original `g4freports` permanece independente.

O módulo mensal reúne os dez IPS, composição financeira por serviços da OS, telefonia das filas exatas `ana` e `ana-transbordo`, evidências e exportações Word, Excel e JSON. O relatório de atividades por profissional da base original continua disponível pelo botão correspondente.

## Atualização para 2.0.5

Em **Cadastros do contrato → Metodologia**, a opção **Incluir desistências no IPS6** controla a participação das chamadas encerradas pelo solicitante antes do atendimento, com espera inferior a 30 segundos. A opção vem marcada, preservando o denominador das versões anteriores. Desmarcada, retira somente essas chamadas do denominador do IPS6; a base operacional conserva os registros e os motivos de exclusão.

A espera das chamadas não atendidas é preservada e acumulada entre passagens vinculadas pelo mesmo identificador. Esperas de 30 segundos ou mais são classificadas como abandono. Ausência de duração não equivale a desistência. Ligações atendidas continuam atendidas, inclusive quando uma transferência posterior não é concluída.

Para usar totais externos com a exclusão ativa, informe também **Desistências (<30 s)** da mesma fonte em **Evidências mensais**. O valor zero deve ser informado quando não houver ocorrências; um campo vazio não comprova zero. Contagens das filas não são subtraídas de totais externos.

Substitua a pasta `g4freportsana`, mantendo a instalação e as configurações existentes. Salve a opção e gere uma nova extração. As regras passam a `ana-2026-11-04-v3`; registros históricos preservam seus resultados e critérios originais. Não há migração de tabelas nem escrita nas fontes GLPI/callcenter.

## Atualização para 2.0.4

Corrige o total de pontos não apurados no Excel, os registros de numerador dos indicadores por prioridade e a apresentação dos estados de aceite de soluções. Remove mensagens de modelo vazio e informações internas das exportações e simplifica a interface mensal. Mantém os cálculos, os recortes e os estados de apuração existentes.

A leitura de telefonia identifica o tipo da coluna por metadados nativos e aceita TIMESTAMP, DATETIME e datas de calendário em campos de texto. O fuso opcional usa America/Sao_Paulo por padrão. Não há alteração de esquema nem consulta de escrita nas fontes de dados.

Substitua a pasta do plugin por esta versão, preservando o nome `g4freportsana`. Conexões, permissões e configurações salvas mantêm a mesma identidade. Gere uma nova extração para incluir a correção de telefonia; reexportar uma extração anterior mantém os dados daquele momento.

## Atualização para 2.0.3

Esta revisão separa descoberta de inicialização: `setup.php` expõe os metadados sem carregar classes, autoloaders ou helpers do relatório. As dependências são carregadas em `plugin_init_g4freportsana()`, depois que o GLPI prepara o carregador do plugin. A revisão elimina essa dependência antecipada; não representa confirmação da causa da ausência observada no servidor. Consulte `DISCOVERY_UPDATE_2.0.3.txt`.

Desative o plugin, substitua os arquivos da pasta `g4freportsana` pelos deste pacote e aplique a atualização/reativação pela administração de plugins do GLPI. Mantenha esse nome de pasta. Não desinstale: a atualização conserva as configurações, credenciais e decisões existentes.

Nas versões 2.0.1 a 2.0.4, as equações ANA, as filas e a versão das regras (`ana-2026-10-16-v2`) foram preservadas. A atualização para 2.0.5 requer nova extração para aplicar a política de desistências.

No GLPI 12, o acesso administrativo respeita a confirmação de identidade nativa do GLPI. Se a confirmação expirar durante um teste ou salvamento AJAX, use **Confirmar identidade no GLPI**, conclua a confirmação na nova aba e volte para repetir a ação. Os campos em edição permanecem na aba original; não há salvamento automático. O GLPI 11 mantém sua proteção por token. O template de configuração 202, introduzido na versão 2.0.2, permanece inalterado nesta revisão.

Consulte `GLPI12_COMPATIBILITY.txt` e `GLPI12_SCHEMA_AUDIT.txt` para as alterações e evidências de compatibilidade introduzidas na versão 2.0.2, preservadas nesta revisão. `COMPATIBILITY_AUDIT.txt` permanece como registro histórico da revisão 2.0.1.

Se o plugin ainda não aparecer, há uma verificação local opcional, somente leitura. Execute na pasta `g4freportsana`:

```bash
php tools/check-package.php
```

O resultado mostra a versão lida de `setup.php`, a localização efetiva desta cópia, dependências indevidas durante a leitura dos metadados e arquivos ausentes ou diferentes do manifesto. Não inicializa GLPI, não consulta bancos, não faz conexões de rede e não altera arquivos. O resultado não identifica por si só os diretórios de busca configurados no GLPI, o acesso do processo web ou o estado de seu cache.

## Instalação

1. Extraia a pasta `g4freportsana` deste pacote para o diretório de plugins reconhecido pelo GLPI. Não renomeie a pasta para `g4freports` nem a sobreponha ao plugin de outro contrato.
2. Instale e ative **Relatórios G4F — ANA** na administração de plugins do GLPI. A instalação grava somente as configurações próprias do plugin; não cria tabelas de chamados, gatilhos ou rotinas agendadas.
3. Em Configurar → Geral → Relatórios G4F — ANA, configure as conexões dedicadas GLPI e callcenter com contas que tenham apenas leitura. A conexão de API é usada pelo relatório de atividades por profissional; não substitui a conexão SQL do relatório mensal.
4. Salve antes de testar as conexões. O diagnóstico usa consultas `LIMIT 0` nas tabelas necessárias. Configure o fuso IANA do PABX se `queue_log.time` for `DATETIME`; o padrão é `America/Sao_Paulo`. Valores `TIMESTAMP` são interpretados pelo instante armazenado.
5. Autorize explicitamente os grupos ou perfis que poderão consultar os dados de todo o contrato. Sem permissões configuradas, somente administradores acessam o gerador. Esta autorização abrange o relatório contratual completo, e não apenas os chamados individualmente visíveis ao usuário no GLPI.
6. Em Ferramentas → Relatórios G4F — ANA, revise a metodologia, as evidências mensais e a composição financeira. A entidade e as equipes são configurações administrativas, não filtros livres do usuário.
7. Selecione a competência e gere o relatório. Confira os indicadores, a revisão de escopo e as bases antes de exportar Word, Excel ou JSON. Mudanças nos cadastros exigem nova extração para afetar um relatório já gerado.

O PHP precisa atender aos requisitos da versão do GLPI instalada (GLPI 12: PHP 8.3 ou superior) e disponibilizar `mysqli` para as conexões SQL. Não há instalação adicional via Composer. Os rascunhos são privados por usuário, ficam no diretório temporário do GLPI e expiram em 24 horas. A desinstalação remove apenas as configurações `plugin:g4freportsana`; exporte as decisões antes de desinstalar. Desativar o plugin conserva essas configurações.

## Proteção das consultas de produção

O módulo mensal executa SELECTs preparados, com recorte mensal, entidade explícita, IDs em lotes e limites de volume. Cada conexão de extração inicia transação somente leitura, aplica fuso UTC à própria sessão e impõe limite de execução de três segundos por consulta no MySQL/MariaDB. Não há `SET GLOBAL`, alteração de índices, atualização de chamados nem consultas agendadas. As leituras consomem recursos; o limite de tempo reduz a exposição, sem prometer impacto nulo.

Uma falha ou excesso de volume na fonte GLPI interrompe a extração sem publicar totais parciais. Falhas no callcenter preservam o relatório GLPI e deixam a telefonia pendente. Os limites não devem ser removidos para contornar uma consulta lenta: confira conexão, plano e índices existentes com a administração do ambiente. Os resultados de consultas distintas não constituem uma única fotografia transacional dos dois bancos.

As configurações e decisões administrativas são gravadas pelo próprio GLPI, mediante POST, proteção nativa de requisições e, no GLPI 12, confirmação de identidade nas operações administrativas. Elas são separadas das conexões de extração. As senhas SQL são protegidas pelo serviço de chaves do GLPI ou fornecidas por variável de ambiente. Não há credenciais incluídas no pacote.

## Critérios de apuração

| Área | Comportamento |
|---|---|
| Competência | Padrão: fechamento no mês, no horário de Brasília. Há comparação por solução e comparação do anexo por abertura UTC mais fechamento. A seleção reconstruída de 514 chamados é cenário de validação, não lista fixa de produção. |
| Equipes | Entidade 0; N1 grupo 4; N2 grupo 1; N3 grupos 22 a 30 como configuração inicial. Responsabilidade na solução, no fechamento ou atual, com evidência histórica e lista de pendências. |
| IPS1 | Média das notas válidas de 1 a 5; pesquisas não respondidas não recebem zero. Corte das respostas explicitado. |
| IPS2 | Percentual com início de tratamento em até cinco minutos, meta de 98%. |
| IPS3 e IPS4 | Incidentes e requisições separados por P1/P2/P3, meta de 90% para cada prioridade. Não há média entre prioridades. |
| Prioridades | Matriz contratual de impacto/urgência; mapeamento alternativo ou exceção exige registro administrativo. Excesso sobre o limite de P1/P2 não causa reclassificação arbitrária de chamados. |
| Tempos | Referência nativa GLPI preserva zero. O relógio contratual usa eventos, continuidade entre equipes, calendário e suspensões aprovadas. Pendências, filas externas ou contadores nativos não autorizam desconto automático de tempo. |
| IPS5 | Chamados distintos com solução e sem reabertura; eventos são mantidos separadamente. Não existe dispensa por reabertura pelo mesmo técnico. |
| IPS6 | Chamadas atendidas sobre encaminhadas, meta de 99%. Interações são vinculadas pelo mesmo identificador de chamada; passagens por fila e contexto entre meses permanecem disponíveis. Identificadores diferentes não são unidos por suposição. |
| IPS7, IPS8 e IPS10 | Disponibilidade por serviço documentado; metas 99,9%, 99,7% e 99,5%. Ausência de fonte não significa 100%. Preço zero do SIEM não significa dispensa. |
| IPS9 | Controles acordados, prazos, conclusão e aceite registrados. Não existe meta genérica inventada para controles não definidos. |
| Medição financeira | Serviços da OS, pontos por IPS/prioridade e TRS, teto de 30%, regras explícitas de frações, arredondamento e PAO. A glosa final usa o total de pontos; valores por linha são indicativos antes de teto e PAO. |

A configuração inicial conserva os tempos nativos como **referência provisória**. A confirmação administrativa deve citar a decisão metodológica aplicável. Alterar a seleção de um relatório invalida a confirmação herdada quando muda o método. Feriados, suspensões, exceções de prioridade, aplicação do PAO, arredondamentos e evidências externas são informados na interface, com referências; não dependem de novas extrações SQL.

O valor mensal inicial da OS 002/2025 é R$ 340.610,90, com sete linhas de serviços e vigência de 01/03/2026 a 03/04/2027. O plugin não prorrateia automaticamente uma competência parcialmente coberta. A confirmação da base de cada mês permanece necessária. Métricas, classificações ou aprovações incompletas mantêm o líquido final pendente; não se convertem em glosa zero.

## Relatórios e rastreabilidade

O Word conserva o papel timbrado G4F e apresenta composição financeira, síntese operacional, IPS, atividades, disponibilidade, SIEM, mudanças/RDM, TRS, VIP, melhorias e validação. Resultados abaixo da meta usam fonte vermelha. Gráficos são objetos nativos editáveis.

O Excel contém indicadores, memória dos operandos, composição financeira e bases de chamados, reaberturas, escopo, soluções sem confirmação e telefonia. A base telefônica inclui identificadores, filas, agentes, horários, espera, conversação, desfecho e eventos. Fórmulas da memória permitem conferir os quocientes; editar a planilha não reexecuta os critérios PHP de histórico, calendário ou classificação. Para reapurar a origem, gere novamente pelo plugin.

O JSON conserva a versão das regras e a extração usada na tela e nos arquivos. Narrativas podem ser atualizadas sem alterar os números daquele rascunho.

## Validação e limites

Esta versão foi validada offline contra o código e o esquema oficiais do GLPI 12.0.1, com os resultados anteriormente fornecidos e com cenários de fronteira. O conjunto de referência preserva sua rotulagem histórica original (11.0.8); isso não é uma identificação consultada da versão atual do servidor. O pacote contém testes e um conjunto de referência sanitizado, sem títulos nem nomes de usuários. Ele não contém uma cópia da base de produção. A instalação e a conexão real no GLPI de destino não foram executadas neste trabalho.

Para executar os testes localmente com PHP CLI, use os arquivos `tests/ana_*_test.php`, um por processo. O teste de exportações aceita um diretório de saída opcional e usa DOM para verificar o OOXML. Não é necessário executar esses testes dentro do servidor de produção. Consulte `VALIDATION.txt` para os resultados da entrega e `tests/compatibility/README.txt` para repetir os testes adicionais de integração GLPI 12 e da interface.

As decisões que dependem de fiscalização, monitoramento ou documentos de aprovação continuam visíveis na interface. O plugin automatiza a apuração disponível e registra as limitações; não transforma uma referência reconstruída em aprovação contratual.
