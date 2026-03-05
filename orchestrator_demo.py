"""
Multi-Agent Orchestrator - Gestion de Copropriete
==================================================
Un coproprietaire pose une question. L'orchestrateur route vers:
- Reponse standard (coproprietaire)
- Compagnie de Gestion 1 (approche terrain/proximite)
- Compagnie de Gestion 2 (approche analytique/performance)

Usage:
    python orchestrator_demo.py              # Lance l'interface Gradio
    python orchestrator_demo.py --console    # Mode console interactif
"""

import argparse
import json
import os
from dotenv import load_dotenv
from openai import OpenAI

load_dotenv()

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

MODEL = "gpt-4o"

client = OpenAI()  # uses OPENAI_API_KEY env var

# ---------------------------------------------------------------------------
# System prompts for each agent
# ---------------------------------------------------------------------------

ORCHESTRATOR_PROMPT = """Tu es un Agent Orchestrateur pour un systeme de gestion de copropriete.
Ton role est de router la requete vers le bon agent selon le profil utilisateur.
Tu ne reponds PAS a la question.

Tu recois le role de l'utilisateur et sa question.
Reponds UNIQUEMENT avec un JSON strict:
{"route": "<base|team1|team2>", "reasoning": "<explication courte du routage>"}

Regles:
- Role "Coproprietaire" -> route "base"
- Role "Gestion ProximaCondo" -> route "team1"
- Role "Gestion StratoGestion" -> route "team2"
"""

BASE_AGENT_PROMPT = """Tu es l'assistant d'un syndicat de copropriete. Tu reponds aux questions
des coproprietaires de maniere courtoise, claire et accessible.

Tu donnes des reponses pratiques sur:
- Les charges de copropriete, les travaux, les assemblees generales
- Les droits et obligations des coproprietaires
- Les parties communes, le reglement de copropriete
- Les demarches administratives

Ton ton est professionnel mais chaleureux. Tu ne donnes pas de perspective de gestionnaire,
tu parles comme un service d'aide aux coproprietaires."""

TEAM1_AGENT_PROMPT = """Tu es l'assistant IA de **ProximaCondo**, une compagnie de gestion
de copropriete reconnue pour son approche de PROXIMITE et son service humain.

Methodologie ProximaCondo:
- "La copropriete, c'est d'abord des gens" - approche centree sur les residents
- Gestionnaire dedie par immeuble (jamais plus de 8 immeubles par gestionnaire)
- Visites mensuelles sur site obligatoires
- Ligne directe 24/7 avec le gestionnaire (pas un centre d'appel)
- Comite consultatif de residents pour chaque decision importante
- Mediation proactive des conflits entre voisins

Quand tu reponds:
1. Reponds a la question de facon complete
2. Ajoute une section "--- ProximaCondo | Notre approche ---" qui montre comment
   ProximaCondo traiterait cette situation avec sa methodologie terrain et humaine.
   Mentionne des actions concretes, des echeanciers, et l'implication des residents.

Signe toujours: "L'equipe ProximaCondo - La gestion a echelle humaine"
"""

TEAM2_AGENT_PROMPT = """Tu es l'assistant IA de **StratoGestion**, une compagnie de gestion
de copropriete reconnue pour son approche ANALYTIQUE et axee sur la performance financiere.

Methodologie StratoGestion:
- "Chaque dollar compte" - optimisation budgetaire rigoureuse
- Tableau de bord numerique en temps reel pour chaque copropriete
- Analyse predictive de l'entretien (prevenir plutot que guerir)
- Appels d'offres systematiques avec minimum 3 soumissions
- Rapport mensuel avec KPIs: taux de recouvrement, cout/unite, fonds de prevoyance
- Planification financiere sur 25 ans avec scenarios

Quand tu reponds:
1. Reponds a la question de facon complete
2. Ajoute une section "--- StratoGestion | Analyse & Recommandations ---" qui montre
   comment StratoGestion traiterait cette situation avec des chiffres, des comparatifs,
   des indicateurs de performance et une strategie financiere.

Signe toujours: "StratoGestion - L'intelligence au service de votre patrimoine"
"""

AGENT_PROMPTS = {
    "base": BASE_AGENT_PROMPT,
    "team1": TEAM1_AGENT_PROMPT,
    "team2": TEAM2_AGENT_PROMPT,
}

AGENT_LABELS = {
    "base": "Coproprietaire (reponse standard)",
    "team1": "ProximaCondo (Gestion de proximite)",
    "team2": "StratoGestion (Gestion analytique)",
}

ROLES = ["Coproprietaire", "Gestion ProximaCondo", "Gestion StratoGestion"]

# ---------------------------------------------------------------------------
# Core logic
# ---------------------------------------------------------------------------


def call_llm(system: str, user_message: str) -> str:
    response = client.chat.completions.create(
        model=MODEL,
        messages=[
            {"role": "system", "content": system},
            {"role": "user", "content": user_message},
        ],
    )
    return response.choices[0].message.content


def orchestrate(role: str, question: str) -> tuple[str, str, str]:
    """Returns (routing_info, agent_label, agent_response)."""
    orchestrator_input = f"Role utilisateur: {role}\nQuestion: {question}"
    raw = call_llm(ORCHESTRATOR_PROMPT, orchestrator_input)

    try:
        data = json.loads(raw)
        route = data["route"]
        reasoning = data["reasoning"]
    except (json.JSONDecodeError, KeyError):
        route = "base"
        reasoning = f"Parsing fallback. Reponse brute: {raw}"
        for key in ("team1", "team2", "base"):
            if key in raw.lower():
                route = key
                break

    routing_info = f"Routage vers: {AGENT_LABELS[route]}\nRaisonnement: {reasoning}"
    agent_response = call_llm(AGENT_PROMPTS[route], question)

    return routing_info, AGENT_LABELS[route], agent_response


# ---------------------------------------------------------------------------
# Console mode
# ---------------------------------------------------------------------------


def run_console():
    print("=" * 60)
    print("  Orchestrateur - Gestion de Copropriete")
    print("=" * 60)

    print("\nQuel est votre profil ?")
    for i, r in enumerate(ROLES, 1):
        print(f"  {i}. {r}")

    choice = input("\nChoix (1/2/3): ").strip()
    try:
        role = ROLES[int(choice) - 1]
    except (ValueError, IndexError):
        role = "Coproprietaire"
        print(f"Choix invalide, utilisation du role: {role}")

    print(f"\nProfil selectionne: {role}")
    print("-" * 60)

    while True:
        question = input("\nVotre question (ou 'quit'): ").strip()
        if question.lower() in ("quit", "exit", "q"):
            break

        print("\n... Analyse par l'Orchestrateur ...")
        routing_info, agent_label, response = orchestrate(role, question)

        print(f"\n{'='*60}")
        print(f"ORCHESTRATEUR: {routing_info}")
        print(f"{'='*60}")
        print(f"\nREPONSE ({agent_label}):\n")
        print(response)
        print(f"\n{'-'*60}")


# ---------------------------------------------------------------------------
# Gradio UI
# ---------------------------------------------------------------------------


def run_gradio():
    import gradio as gr

    def handle(role, question):
        if not question.strip():
            return "Veuillez poser une question.", ""
        routing_info, _, response = orchestrate(role, question)
        return routing_info, response

    with gr.Blocks(title="Orchestrateur Copropriete") as demo:
        gr.Markdown("# Orchestrateur - Gestion de Copropriete")
        gr.Markdown(
            "Selectionnez votre profil et posez une question sur la copropriete. "
            "L'orchestrateur routera vers l'agent adapte."
        )

        with gr.Row():
            role_dd = gr.Dropdown(
                choices=ROLES,
                value="Coproprietaire",
                label="Quel est votre profil ?",
            )

        question_tb = gr.Textbox(
            label="Votre question",
            placeholder="Ex: Le toit coule, qui doit payer les reparations ?",
            lines=3,
        )
        submit_btn = gr.Button("Envoyer", variant="primary")

        routing_tb = gr.Textbox(label="Raisonnement de l'Orchestrateur", lines=3)
        response_tb = gr.Textbox(label="Reponse de l'Agent", lines=15)

        submit_btn.click(
            fn=handle,
            inputs=[role_dd, question_tb],
            outputs=[routing_tb, response_tb],
        )

    demo.launch()


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Orchestrateur Copropriete")
    parser.add_argument("--console", action="store_true", help="Mode console interactif")
    args = parser.parse_args()

    if args.console:
        run_console()
    else:
        run_gradio()
