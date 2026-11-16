## 2.0.5 — 2026-11-04

- Opção administrativa para incluir ou excluir desistências com espera inferior a 30 segundos do IPS6; inclusão mantida como padrão.
- Preservação da espera das ligações não atendidas e consolidação da espera entre passagens pelo mesmo identificador.
- Desistências, abandonos e abandonos sem tempo de espera identificado apresentados separadamente; chamadas excluídas do indicador permanecem na base operacional.
- Critério de participação preservado na extração e nas exportações; registros históricos não são recalculados pela configuração atual.
- Contagem de desistências da própria fonte para apuração com totais externos; ausência de contagem não é convertida em zero.

## 2.0.4 — 2026-11-04

- Corrige os totais financeiros indisponíveis e a evidência de numerador de IPS3/IPS4 no Excel.
- Separa aceite de soluções e ausência de registro de solução.
- Remove conteúdo de modelo vazio, mensagens internas e repetição nas exportações e na interface.
- Preserva o conteúdo de relatórios salvos ao aplicar a apresentação revisada.
- Identifica o armazenamento temporal da telefonia por metadados nativos e aceita datas de calendário em campos de texto.
- Mantém a compatibilidade GLPI 11/12, controles de acesso, segurança nativa e limites de leitura.

# Histórico

## 2.0.3 — 30/10/2026

- Separação da descoberta e da inicialização: setup.php retorna metadados sem carregar helpers, autoloaders ou classes de configuração/menu.
- Dependências carregadas pelo callback de inicialização, após o carregador nativo do GLPI.
- Regressão de metadados sem aplicação GLPI e teste de descoberta com Composer e autoloader legado reais.
- Verificador CLI de metadados e integridade, somente leitura e sem inicialização do GLPI.
- Regras ANA, SQL, hooks, identidade, segurança GLPI12, interface e exportadores preservados.
- Não foi reproduzida a ausência observada no servidor; a revisão elimina uma dependência prematura comprovada sem alegar causa confirmada.

## 2.0.2 — 29/10/2026

- Compatibilidade revisada contra o código e o esquema oficiais do GLPI 12.0.1, mantendo suporte ao GLPI 11; limite superior exclusivo GLPI 13.
- Proteção nativa de requisições do GLPI 12, sem invocar a API de tokens obsoleta; tokens preservados no GLPI 11.
- Confirmação de identidade nativa para configuração, diagnósticos e alterações administrativas; recuperação AJAX sem perda dos campos em edição.
- Template 202 e metadados de versão consistentes; atualização preserva configurações e credenciais.
- 105 campos em 16 tabelas conferidos; consultas, filas, cálculos ANA, regras financeiras e geradores Word/Excel preservados.
- Testes adicionais com classes reais do GLPI 12.0.1 e regressões DOM da interface, executados offline.

## 2.0.1 — 16/10/2026

- Revisão comparativa com o plugin funcional 1.8.19; estrutura GLPI, hooks, autoload e relatório profissional preservados.
- Correção do CSRF nativo GLPI 11 em requisições simultâneas e bloqueio de respostas obsoletas após alterações do mês, método ou textos.
- Correção das narrativas Word/Excel e apresentação dos ajustes que compõem o líquido financeiro.
- Aceitação estrita de frações de segundo em DATETIME telefônico e consistência da disponibilidade histórica no manifesto.
- Correção da leitura de números de teste ativos e preservação da cronologia bruta de eventos telefônicos no Excel; revisão de regras v2 exige nova geração dos rascunhos.
- Template de configuração 201 para evitar reutilização da versão anterior pelo cache Twig.
- Relatório COMPATIBILITY_AUDIT.txt com diferenças necessárias, mudanças adicionais e limites da validação.

## 2.0.0 — 16/10/2026

- Fork independente ANA a partir de G4F Reports 1.8.19, com compatibilidade direcionada ao GLPI 11.
- Dez IPS contratuais, médias e percentuais com tipos distintos e resultados separados por prioridade.
- Aquisição GLPI 11 com histórico de equipes, reaberturas e soluções; preservação de tempos zero e pendências de escopo.
- Filas exatas `ana` e `ana-transbordo`, detalhamento por evento e consolidação comprovada pelo identificador de chamada.
- Cadastro administrativo de metodologia, feriados, suspensões, prioridades, escopo, disponibilidade, controles e composição financeira.
- Medição por serviços da OS, pontos IPS/TRS, teto, PAO e arredondamentos explicitados.
- Prévia unificada, exportações Word, Excel e JSON, com evidência dos resultados e das pendências.
- Permissão contratual explícita, parâmetros de escopo administrativos e proteção dos rascunhos por proprietário.
- Consultas preparadas somente leitura, com limites de volume e execução por sessão.
- Testes com dados sanitizados das extrações fornecidas e verificação estrutural e visual dos arquivos exportados.
## 2.0.6 — 2026-11-16

- Corrige a prioridade contratual para usar a prioridade final do chamado no GLPI, incluindo atribuições por regras de negócio: 1/2=P3, 3=P2 e 4/5/6=P1.
- Preserva exceções por chamado, VIP e mapeamentos administrativos explícitos, que têm precedência sobre a correspondência padrão.
- Mantém a apuração como provisória até confirmação administrativa da metodologia e exige nova extração para refletir a correção em IPS3, IPS4 e no limite de 70%.
- Adiciona regressão para prioridade baixa no GLPI com urgência e impacto médios.
- Exclui da distribuição pública a base de testes derivada de produção e os testes de reprodução histórica dependentes dela; mantém testes sintéticos independentes.

