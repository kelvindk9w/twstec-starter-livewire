-- Banco EXCLUSIVO da suíte de testes (phpunit.pgsql.xml), ao lado do banco de
-- desenvolvimento. A suíte apaga e recria o schema a cada execução; por isso
-- ela nunca roda no banco do .env (tests/TestCase.php recusa banco cujo nome
-- não termine em _test).
--
-- O Postgres só executa este diretório na PRIMEIRA subida, com o volume vazio.
-- Volume já existente: criar à mão (comando em docs/testes.md, "Testes
-- contra o PostgreSQL").
CREATE DATABASE tws_starter_test;
