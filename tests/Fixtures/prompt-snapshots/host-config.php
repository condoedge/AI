<?php

/*
 * The host config the prompt snapshots are taken under (dot keys). It stands in
 * for CondoEdge: when a domain slot moves out of the package, the text CondoEdge
 * supplies for it is added here, so the snapshots keep proving byte-identity.
 */

return [
    'ai.project.name' => 'Snapshot Host',
    'ai.project.description' => 'A property management platform: buildings, units, residents, fees, meetings and documents.',
    'ai.project.domain' => 'property management',
    'ai.project.business_rules' => [],
    'ai.identity.name' => 'Snapshot Assistant',
    'ai.file_context.corpus_languages' => ['en', 'fr'],

    // Slot (a): CondoEdge's QU examples.
    'ai.domain.understanding' => [
        'implicit_entity_example' => 'if asking about "rent payments", include both the payment entity and the unit/property entity',
        'property_concept_examples' => '"insurance", "email", "balance", "birthday", "parking"',
        'possessive_examples' => '"my unit", "my invoices", "do I have", "am I"',
        'clarified_example' => [
            'question' => 'How many parking spaces do I have?',
            'clarified' => 'How many parking space entities are related to the person entity representing me in the graph?',
        ],
        'no_owner_example' => [
            'question' => 'Show me the parking spaces',
            'clarified' => 'Which parking space entities exist in the graph?',
        ],
        'knowledge_example' => [
            'question' => 'Can i tear down a wall in my apartment?',
            'clarified' => 'What are the rules and regulations regarding modifying a residential unit in my building, more specifically tearing down a wall, and how do they apply to me as a tenant?',
        ],
    ],

    // Slot (b): CondoEdge's query-rule examples.
    'ai.domain.query_rules' => [
        'join_example' => 'JOIN Contact for BoardMember',
        'record_examples' => 'unit, supplier, meeting, member',
        'record_id_example' => 'u.id AS unit_id',
        'row_grain_examples' => 'members, units, invoices',
    ],

    // Slot (c).
    'ai.domain.ambiguous_profile_labels' => ['Contact'],

    // Slot (d): CondoEdge's alias vocabulary.
    'ai.domain.aliases' => [
        'union' => ['copropriete', 'copropriété', 'syndicat', 'condo', 'condominium', 'HOA', 'strata'],
        'unit' => ['unité', 'unite', 'fraction', 'lot', 'logement', 'apartment', 'apt', 'suite'],
        'building' => ['immeuble', 'bâtiment', 'batiment', 'édifice', 'edifice'],
        'contact' => ['résident', 'resident', 'propriétaire', 'owner', 'tenant', 'locataire', 'occupant'],
        'invoice' => ['facture', 'cotisation', 'contribution', 'assessment', 'bill', 'statement', 'receipt'],
        'bill' => ['facture fournisseur', 'dépense', 'expense'],
        'supplier' => ['fournisseur', 'vendor', 'contractor', 'entrepreneur', 'prestataire'],
        'task' => ['tâche', 'tache', 'to-do', 'action item', 'suivi'],
        'meeting' => ['réunion', 'reunion', 'assemblée', 'assemblee', 'assemblée générale', 'AGM'],
        'budget' => ['budget', 'prévision', 'prevision'],
        'fund' => ['fonds', 'reserve fund', 'fonds de prévoyance', 'contingency fund'],
        'account' => ['compte', 'ledger', 'GL account'],
        'transaction' => ['transaction', 'paiement', 'payment', 'deposit', 'dépôt'],
        'entry' => ['écriture', 'ecriture', 'journal entry'],
        'tax' => ['taxe', 'TPS', 'TVQ', 'GST', 'QST'],
        'event' => ['événement', 'evenement', 'activité', 'activite'],
        'reservation' => ['réservation', 'booking'],
        'resource' => ['ressource', 'common area', 'aire commune', 'amenity'],
        'announcement' => ['annonce', 'avis', 'notice'],
        'discussion' => ['discussion', 'forum', 'sujet'],
        'maintenance' => ['entretien', 'maintenance'],
        'work_order' => ['bon de travail', 'demande de service', 'service request'],
        'project' => ['projet', 'capital project'],
        'component' => ['composante', 'équipement', 'equipement', 'equipment'],
        'pet' => ['animal', 'animal de compagnie'],
        'vehicle' => ['véhicule', 'vehicule', 'voiture', 'car'],
        'vote' => ['vote', 'scrutin', 'ballot'],
        'motion' => ['résolution', 'resolution', 'proposition'],
        'file' => ['fichier', 'document', 'pièce jointe', 'attachment', 'upload'],
        'folder' => ['dossier', 'répertoire'],
        'note' => ['note', 'commentaire', 'comment'],
        'comment' => ['note', 'commentaire', 'remark', 'feedback'],
        'access' => ['accès', 'acces', 'clé', 'cle', 'fob', 'télécommande', 'telecommande'],
        'visit' => ['visite', 'visiteur', 'visitor'],
    ],

    // Slot (e): CondoEdge's ai:discover examples.
    'ai.domain.discovery' => [
        'app_brief' => 'a condo management AI assistant',
        'persona_examples' => 'owner vs member vs renter',
        'possessive_path_example' => '"my unit" = User→Contact→ContactUnit→Unit',
        'possessive_alias_examples' => '"my unit", "mes unités"',
        'question_examples' => '["How many units do we have?", "Show me my unit", "Combien d\'unités avons-nous?", "Which units have pets?", "List units in building X"]',
        'relationship_examples' => '{"BUILDING": "The building this unit is located in", "HAS_CONTACT_UNITS": "Contacts linked to this unit via the ContactUnit junction table (owners, renters, occupants)"}',
        'junction_example' => 'ContactUnit links Contact↔Unit',
        'possessive_chain_example' => 'User→Contact→ContactUnit→Unit (for unit), User→Contact (for contact info)',
    ],
];
