# Guia de Contribuição — Laravel Resilience Kit

Obrigado pelo seu interesse em contribuir para o **Laravel Resilience Kit**! Este documento descreve o fluxo de trabalho, os padrões de código e a execução de testes utilizando o **Laravel Sail** com as versões atuais das ferramentas (PHP 8.3/8.4, Redis, Pest PHP).

---

## 🛠️ Configuração do Ambiente de Desenvolvimento com Laravel Sail

O projeto utiliza o **Laravel Sail** (Docker) para garantir um ambiente idêntico entre todos os desenvolvedores.

### Requisitos Próximos
- Docker e Docker Compose instalados
- Git
- PHP 8.3 ou superior e Composer 2.x (apenas para a instalação inicial caso não use o container)

### Passos de Configuração Inicial

1. Faça o **Fork** e clone o repositório:
   ```bash
   git clone git@github.com:seu-usuario/laravel-resilience-kit.git
   cd laravel-resilience-kit
   ```

2. Instale as dependências e inicie os serviços com Laravel Sail:
   ```bash
   # Instalar dependências via Composer no container do Sail
   docker run --rm \
       -u "$(id -u):$(id -g)" \
       -v "$(pwd):/var/www/html" \
       -w /var/www/html \
       laravelsail/php83-composer:latest \
       composer install --ignore-platform-reqs

   # Iniciar os serviços (PHP 8.3 + Redis) em segundo plano
   ./vendor/bin/sail up -d
   ```

3. Caso prefira criar um alias local para o Sail:
   ```bash
   alias sail='./vendor/bin/sail'
   ```

---

## 🧪 Execução de Testes Unitários e Análise Estática

Todas as contribuições precisam de **100% de aprovação nos testes unitários e análise estática**.

### 1. Suíte de Testes (Pest PHP)

Rodar os testes unitários e de integração via Sail:
```bash
# Executar todos os testes
./vendor/bin/sail pest

# Executar apenas testes unitários
./vendor/bin/sail pest --group=unit

# Executar com relatório de cobertura de código
./vendor/bin/sail pest --coverage
```

### 2. Formatação de Código (Laravel Pint)
Mantemos o padrão rigoroso PSR-12 / Laravel usando o **Laravel Pint**:
```bash
# Verificar se há violações de estilo
./vendor/bin/sail pint --test

# Corrigir violações automaticamente
./vendor/bin/sail pint
```

### 3. Análise Estática (Larastan / PHPStan)
Exigimos nível de análise estática sem erros (`level: 8`):
```bash
./vendor/bin/sail phpstan
```

---

## 📐 Padrões de Código e Requisitos de Qualidade

1. **Strict Types:** Todo arquivo `.php` DEVE iniciar com `declare(strict_types=1);`.
2. **PHP Moderno (PHP 8.3+):** Utilize Enums com tipo (`CircuitState`), readonly properties, Named Arguments e Atributos PHP.
3. **Contratos e Interfaces:** Defina interfaces no namespace `LaravelResilienceKit\CircuitBreaker\Contracts\` ou `LaravelResilienceKit\Contracts\`.
4. **Testes Obrigatórios:** Toda nova funcionalidade ou correção de bug DEVE incluir um teste unitário em Pest cobrindo o caminho feliz, cenários de falha e fallbacks.
5. **Composição em vez de Herança:** Evite herança profunda de classes. Prefira interfaces e injeção de dependências.

---

## 🔀 Fluxo de Pull Request (PR)

1. Crie uma branch descritiva a partir de `main`:
   ```bash
   git checkout -b feature/nome-da-funcionalidade
   # ou
   git checkout -b fix/descricao-do-bug
   ```

2. Implemente o código e adicione os testes em `tests/Unit/` ou `tests/Feature/`.

3. Rode a verificação completa localmente com Sail:
   ```bash
   ./vendor/bin/sail pest && ./vendor/bin/sail phpstan && ./vendor/bin/sail pint
   ```

4. Faça os commits seguindo a convenção de **Conventional Commits**:
   - `feat: adiciona suporte a fallback por classe dedicada`
   - `fix: corrige contagem atômica de falhas no driver Redis`
   - `test: adiciona testes para transição HALF-OPEN`
   - `docs: atualiza instruções de uso com Sail`

5. Abra o Pull Request detalhando o problema resolvido, a solução adotada e o resultado dos testes.
