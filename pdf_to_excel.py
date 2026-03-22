"""
Conversor de PDF (relatórios ANP) para Excel.

Uso:
    python pdf_to_excel.py <arquivo.pdf> [saida.xlsx]

Caso o arquivo de saída não seja informado, ele será criado com o mesmo nome
do PDF substituindo a extensão por .xlsx.
"""

import sys
import os
import re
import pdfplumber
import pandas as pd


def extrair_tabelas_pdf(caminho_pdf: str) -> list[pd.DataFrame]:
    """Extrai todas as tabelas encontradas no PDF como DataFrames."""
    tabelas = []

    with pdfplumber.open(caminho_pdf) as pdf:
        for numero_pagina, pagina in enumerate(pdf.pages, start=1):
            tabelas_pagina = pagina.extract_tables()
            for tabela in tabelas_pagina:
                if not tabela:
                    continue
                # A primeira linha geralmente contém os cabeçalhos
                cabecalhos = tabela[0]
                linhas = tabela[1:]

                # Remove linhas completamente vazias
                linhas = [l for l in linhas if any(c for c in l if c and str(c).strip())]

                if not linhas:
                    continue

                # Garante que todos os cabeçalhos são strings únicas
                cabecalhos_limpos = _limpar_cabecalhos(cabecalhos)

                df = pd.DataFrame(linhas, columns=cabecalhos_limpos)

                # Remove colunas completamente vazias
                df = df.dropna(axis=1, how="all")
                df = df.loc[:, ~(df == "").all()]

                # Tenta converter colunas numéricas
                df = _converter_numeros(df)

                tabelas.append(df)

    return tabelas


def _limpar_cabecalhos(cabecalhos: list) -> list[str]:
    """Garante que cada cabeçalho seja uma string não-vazia e única."""
    resultado = []
    contagem: dict[str, int] = {}
    for i, col in enumerate(cabecalhos):
        nome = str(col).strip() if col is not None else ""
        if not nome:
            nome = f"Coluna_{i + 1}"
        if nome in contagem:
            contagem[nome] += 1
            nome = f"{nome}_{contagem[nome]}"
        else:
            contagem[nome] = 0
        resultado.append(nome)
    return resultado


def _converter_numeros(df: pd.DataFrame) -> pd.DataFrame:
    """Tenta converter colunas que parecem numéricas."""
    for col in df.columns:
        convertido = df[col].apply(_tentar_converter_numero)
        if convertido.notna().sum() > 0:
            df[col] = convertido
    return df


def _tentar_converter_numero(valor):
    """Converte valor para float se possível.

    Reconhece:
    - Formato BR com decimal: '1.234,56' → 1234.56
    - Formato BR sem decimal: '1.234' → 1234.0  (só quando há ponto como milhar)
    - Formato padrão: '1234.56' → 1234.56
    - Inteiros: '42' → 42.0
    """
    if valor is None:
        return None
    texto = str(valor).strip()
    if not texto:
        return None

    if "," in texto:
        # Formato BR: ponto = separador de milhar, vírgula = decimal
        texto_br = re.sub(r"\.", "", texto).replace(",", ".")
        try:
            return float(texto_br)
        except ValueError:
            return valor

    # Sem vírgula: tenta float padrão diretamente (ponto = decimal)
    try:
        return float(texto)
    except ValueError:
        return valor


def extrair_texto_fallback(caminho_pdf: str) -> list[pd.DataFrame]:
    """
    Extrai texto linha a linha como fallback quando nenhuma tabela estruturada
    é encontrada. Retorna um DataFrame com coluna única "Texto".
    """
    linhas = []
    with pdfplumber.open(caminho_pdf) as pdf:
        for pagina in pdf.pages:
            texto = pagina.extract_text()
            if texto:
                for linha in texto.splitlines():
                    linha = linha.strip()
                    if linha:
                        linhas.append({"Texto": linha})

    if linhas:
        return [pd.DataFrame(linhas)]
    return []


def salvar_excel(tabelas: list[pd.DataFrame], caminho_saida: str) -> None:
    """Salva uma lista de DataFrames em abas separadas de um arquivo Excel."""
    if not tabelas:
        raise ValueError("Nenhum dado foi extraído do PDF.")

    with pd.ExcelWriter(caminho_saida, engine="openpyxl") as writer:
        for i, df in enumerate(tabelas, start=1):
            nome_aba = f"Tabela_{i}" if len(tabelas) > 1 else "Dados"
            # O Excel limita nomes de abas a 31 caracteres
            nome_aba = nome_aba[:31]
            df.to_excel(writer, sheet_name=nome_aba, index=False)

    print(f"Arquivo salvo em: {caminho_saida}")
    print(f"Total de abas: {len(tabelas)}")
    for i, df in enumerate(tabelas, start=1):
        print(f"  Tabela {i}: {len(df)} linhas × {len(df.columns)} colunas")


def converter(caminho_pdf: str, caminho_saida: str | None = None) -> str:
    """
    Converte um arquivo PDF para Excel.

    Args:
        caminho_pdf: Caminho para o arquivo PDF de entrada.
        caminho_saida: Caminho para o arquivo Excel de saída (opcional).

    Returns:
        Caminho do arquivo Excel gerado.
    """
    if not os.path.isfile(caminho_pdf):
        raise FileNotFoundError(f"Arquivo PDF não encontrado: {caminho_pdf}")

    if caminho_saida is None:
        base, _ = os.path.splitext(caminho_pdf)
        caminho_saida = base + ".xlsx"

    print(f"Lendo PDF: {caminho_pdf}")

    tabelas = extrair_tabelas_pdf(caminho_pdf)

    if not tabelas:
        print("Nenhuma tabela estruturada encontrada. Extraindo texto puro...")
        tabelas = extrair_texto_fallback(caminho_pdf)

    if not tabelas:
        raise ValueError("Não foi possível extrair dados do PDF.")

    salvar_excel(tabelas, caminho_saida)
    return caminho_saida


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)

    caminho_pdf = sys.argv[1]
    caminho_saida = sys.argv[2] if len(sys.argv) > 2 else None

    try:
        converter(caminho_pdf, caminho_saida)
    except (FileNotFoundError, ValueError) as e:
        print(f"Erro: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
