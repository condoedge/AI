<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * AliasGenerator
 *
 * Generates semantic aliases from model and table names for better
 * query matching. Creates singular, plural, and common business term
 * variations to improve entity recognition in natural language queries.
 * Also reads Laravel translation files to add French aliases automatically.
 *
 * Usage:
 *   $generator = new AliasGenerator();
 *   $aliases = $generator->generate($customer);
 *   // ['customer', 'customers', 'client', 'buyer', 'unité', ...]
 *
 * @package Condoedge\Ai\Services\Discovery
 */
class AliasGenerator
{
    /**
     * Cached French translations (key => French value)
     *
     * @var array|null
     */
    private ?array $frenchTranslations = null;

    /**
     * Common business term mappings
     *
     * Maps entity names to their common business synonyms,
     * including French translations and condo management domain terms.
     */
    private const BUSINESS_TERMS = [
        'customer' => ['client', 'buyer', 'patron', 'account'],
        'user' => ['member', 'person', 'individual', 'account'],
        'order' => ['purchase', 'sale', 'transaction'],
        'product' => ['item', 'good', 'merchandise', 'article'],
        'payment' => ['transaction', 'remittance'],
        'employee' => ['worker', 'staff', 'personnel', 'team member'],
        'company' => ['organization', 'business', 'enterprise', 'firm'],
        'address' => ['location', 'place'],
        'phone' => ['telephone', 'contact number', 'mobile'],
        'email' => ['e-mail', 'electronic mail', 'contact'],
        'category' => ['type', 'class', 'group'],
        'tag' => ['label', 'keyword'],
        'post' => ['article', 'entry', 'content'],
        'image' => ['photo', 'picture', 'graphic'],
        'team' => ['group', 'department', 'division'],
        'role' => ['position', 'title', 'function'],
        'permission' => ['right', 'privilege'],

        // Condo management domain terms (bilingual EN/FR)
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
    ];

    /**
     * Generate aliases from model or table name
     *
     * Creates variations including singular, plural, and common
     * business terms.
     *
     * @param string|Model $model Model class name or instance
     * @return array List of unique aliases
     */
    public function generate(string|Model $model): array
    {
        $modelInstance = $this->resolveModel($model);

        try {
            $tableName = $modelInstance->getTable();
        } catch (\Throwable $e) {
            $tableName = null;
        }

        $className = class_basename($model);

        // Remove 'Test' prefix if present
        if (str_starts_with($className, 'Test')) {
            $className = substr($className, 4);
        }

        $aliases = [];

        // Add inflections from table name (if available)
        if ($tableName !== null) {
            $aliases = array_merge($aliases, $this->inflections($tableName));
        }

        // Add inflections from class name
        $classBase = Str::snake($className);
        $aliases = array_merge($aliases, $this->inflections($classBase));

        // Add business terms
        if ($tableName !== null) {
            $aliases = array_merge($aliases, $this->businessTerms($tableName));
        }
        $aliases = array_merge($aliases, $this->businessTerms($classBase));

        // Add French translations from Laravel JSON translation files
        $label = Str::studly($classBase);
        $aliases = array_merge($aliases, $this->fromTranslations($label));

        // Remove duplicates and return
        return array_values(array_unique($aliases));
    }

    /**
     * Generate plural/singular inflections
     *
     * @param string $word Word to inflect
     * @return array Inflected forms
     */
    private function inflections(string $word): array
    {
        $word = strtolower($word);

        // Remove common table prefixes/suffixes
        $word = $this->normalizeWord($word);

        $singular = Str::singular($word);
        $plural = Str::plural($word);

        $inflections = [$singular, $plural];

        // Add variations
        $inflections[] = str_replace('_', ' ', $singular);
        $inflections[] = str_replace('_', ' ', $plural);

        // Add StudlyCase variations
        $inflections[] = Str::lower(Str::studly($singular));
        $inflections[] = Str::lower(Str::studly($plural));

        return array_unique($inflections);
    }

    /**
     * Get business terms for a word
     *
     * Looks up common business synonyms from the mapping.
     *
     * @param string $word Word to look up
     * @return array Business term synonyms
     */
    private function businessTerms(string $word): array
    {
        $word = strtolower($word);
        $word = $this->normalizeWord($word);

        // Check both singular and plural forms
        $singular = Str::singular($word);
        $plural = Str::plural($word);

        $terms = [];

        // Check if we have business terms for singular form
        if (isset(self::BUSINESS_TERMS[$singular])) {
            $terms = array_merge($terms, self::BUSINESS_TERMS[$singular]);

            // Add pluralized versions of business terms
            foreach (self::BUSINESS_TERMS[$singular] as $term) {
                $terms[] = Str::plural($term);
            }
        }

        // Check if we have business terms for plural form
        if (isset(self::BUSINESS_TERMS[$plural])) {
            $terms = array_merge($terms, self::BUSINESS_TERMS[$plural]);
        }

        return array_unique($terms);
    }

    /**
     * Get French translations for an entity label from Laravel JSON translation files
     *
     * Looks up the entity name (and its plural) in the French translation file,
     * then generates inflections (singular/plural, with and without accents)
     * for all found translations.
     *
     * @param string $label The StudlyCase entity label (e.g., "Unit", "Building")
     * @return array French translation aliases
     */
    public function fromTranslations(string $label): array
    {
        $translations = $this->loadFrenchTranslations();

        if (empty($translations)) {
            return [];
        }

        $aliases = [];

        // Try singular form as-is (e.g., "Unit")
        $singular = Str::singular($label);
        $plural = Str::plural($label);

        // Also try space-separated forms for multi-word labels (e.g., "WorkOrder" -> "Work Order")
        $singularSpaced = $this->studlyToSpaced($singular);
        $pluralSpaced = $this->studlyToSpaced($plural);

        // Collect all candidate keys to look up
        $candidates = array_unique([$singular, $plural, $singularSpaced, $pluralSpaced]);

        foreach ($candidates as $candidate) {
            if (isset($translations[$candidate])) {
                $frenchTerm = $translations[$candidate];

                // Skip if the French translation is the same as the English key
                // (no actual translation available)
                if (strtolower($frenchTerm) === strtolower($candidate)) {
                    continue;
                }

                $aliases[] = strtolower($frenchTerm);

                // Generate singular/plural inflections of the French term
                $frenchSingular = Str::singular($frenchTerm);
                $frenchPlural = Str::plural($frenchTerm);

                $aliases[] = strtolower($frenchSingular);
                $aliases[] = strtolower($frenchPlural);

                // Add accent-stripped versions for easier matching
                $stripped = $this->stripAccents(strtolower($frenchTerm));
                if ($stripped !== strtolower($frenchTerm)) {
                    $aliases[] = $stripped;
                    $aliases[] = $this->stripAccents(strtolower($frenchSingular));
                    $aliases[] = $this->stripAccents(strtolower($frenchPlural));
                }
            }
        }

        return array_values(array_unique($aliases));
    }

    /**
     * Load French translations from Laravel JSON translation files
     *
     * Reads the fr.json translation file and caches the result.
     * Supports both the app-level and package-level translation files.
     *
     * @return array Key-value pairs of English => French translations
     */
    private function loadFrenchTranslations(): array
    {
        if ($this->frenchTranslations !== null) {
            return $this->frenchTranslations;
        }

        $this->frenchTranslations = [];

        // Try to use Laravel's translator if available in the app container
        if (function_exists('app') && app()->bound('translator')) {
            try {
                $translator = app('translator');

                // Load all JSON translations for French locale
                $frenchLoader = $translator->getLoader();
                $jsonTranslations = $frenchLoader->load('fr', '*', '*');

                if (is_array($jsonTranslations) && !empty($jsonTranslations)) {
                    $this->frenchTranslations = $jsonTranslations;
                    return $this->frenchTranslations;
                }
            } catch (\Throwable $e) {
                // Fall through to file-based loading
            }
        }

        // Fallback: read fr.json files directly from disk
        $possiblePaths = [
            base_path('resources/lang/fr.json'),
            base_path('lang/fr.json'),
        ];

        // Also check if we can find package translation paths
        if (function_exists('base_path')) {
            $possiblePaths[] = base_path('vendor/condoedge/ai/resources/lang/fr.json');
        }

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                $decoded = json_decode($content, true);

                if (is_array($decoded)) {
                    $this->frenchTranslations = array_merge($this->frenchTranslations, $decoded);
                }
            }
        }

        return $this->frenchTranslations;
    }

    /**
     * Convert a StudlyCase string to space-separated words
     *
     * @param string $studly StudlyCase string (e.g., "WorkOrder")
     * @return string Space-separated string (e.g., "Work Order")
     */
    private function studlyToSpaced(string $studly): string
    {
        return preg_replace('/([a-z])([A-Z])/', '$1 $2', $studly);
    }

    /**
     * Strip accents from a string for accent-insensitive matching
     *
     * @param string $string String with potential accents
     * @return string String with accents removed
     */
    private function stripAccents(string $string): string
    {
        if (function_exists('transliterator_transliterate')) {
            $result = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC;', $string);
            return $result !== false ? $result : $string;
        }

        // Fallback for environments without intl extension
        $accents = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
            'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'é' => 'e',
            'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ç' => 'c', 'ñ' => 'n',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A',
            'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'É' => 'E',
            'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Í' => 'I',
            'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Ó' => 'O',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ú' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ];

        return strtr($string, $accents);
    }

    /**
     * Normalize word by removing common prefixes/suffixes
     *
     * @param string $word Word to normalize
     * @return string Normalized word
     */
    private function normalizeWord(string $word): string
    {
        // Remove test_ prefix
        if (str_starts_with($word, 'test_')) {
            $word = substr($word, 5);
        }

        // Remove _tbl suffix
        if (str_ends_with($word, '_tbl')) {
            $word = substr($word, 0, -4);
        }

        // Remove _table suffix
        if (str_ends_with($word, '_table')) {
            $word = substr($word, 0, -6);
        }

        return $word;
    }

    /**
     * Resolve model to instance
     *
     * @param string|Model $model Model class name or instance
     * @return Model Model instance
     */
    private function resolveModel(string|Model $model): Model
    {
        if (is_string($model)) {
            return new $model();
        }

        return $model;
    }

    /**
     * Get label from model or table name
     *
     * Returns a StudlyCase label suitable for Neo4j.
     *
     * @param string|Model $model Model class name or instance
     * @return string Label
     */
    public function generateLabel(string|Model $model): string
    {
        $modelInstance = $this->resolveModel($model);
        $className = class_basename($model);

        // Remove 'Test' prefix if present
        if (str_starts_with($className, 'Test')) {
            $className = substr($className, 4);
        }

        // If model implements Nodeable, use getNodeLabel
        if (method_exists($modelInstance, 'getNodeLabel')) {
            return $modelInstance->getNodeLabel();
        }

        return $className;
    }
}
