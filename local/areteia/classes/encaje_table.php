<?php
namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

/**
 * Tabla de encaje pedagógico: mapea instrumentos de evaluación a los
 * instrumentos de corrección válidos según criterios pedagógicos.
 *
 * Fuente: RAG_encaje_2.docx.md
 */
class encaje_table {

    /**
     * Instrumentos de corrección disponibles para cada instrumento de evaluación.
     * Claves normalizadas (sin acentos, snake_case).
     */
    public const ENCAJE = [
        'Análisis de fuentes documentales'  => ['lista_cotejo', 'escala_valoracion', 'rubrica'],
        'Aprendizaje servicio (APS)'        => ['lista_cotejo', 'escala_valoracion', 'rubrica'],
        'Cuestionario'                      => ['clave_correccion'],
        'Debate'                            => ['lista_cotejo', 'escala_valoracion', 'rubrica'],
        'Ensayo / Desarrollo'               => ['escala_valoracion', 'rubrica'],
        'Escape room'                       => ['clave_correccion', 'lista_cotejo'],
        'Esquema'                           => ['lista_cotejo', 'escala_valoracion'],
        'Estudio de caso / Análisis de casos' => ['escala_valoracion', 'rubrica'],
        'Evaluación auténtica'              => ['lista_cotejo', 'escala_valoracion', 'rubrica'],
        'Evaluación oral'                   => ['lista_cotejo', 'escala_valoracion', 'rubrica'],
        'Glosario'                          => ['clave_correccion', 'lista_cotejo'],
        'Juego de rol'                      => ['lista_cotejo', 'escala_valoracion', 'rubrica'],
        'Mapa conceptual'                   => ['lista_cotejo', 'escala_valoracion'],
        'Monografía'                        => ['escala_valoracion', 'rubrica'],
        'Portafolio'                        => ['lista_cotejo', 'escala_valoracion', 'rubrica'],
        'Prácticas / Pruebas clínicas'      => ['clave_correccion', 'lista_cotejo', 'escala_valoracion', 'rubrica'],
        'Prácticas de laboratorio'          => ['lista_cotejo', 'escala_valoracion', 'rubrica'],
        'Proyectos de investigación'        => ['lista_cotejo', 'escala_valoracion', 'rubrica'],
        'Prueba mixta'                      => ['clave_correccion', 'lista_cotejo', 'escala_valoracion', 'rubrica'],
        'Recensión bibliográfica'           => ['lista_cotejo', 'escala_valoracion', 'rubrica'],
        'Resolución de problemas abiertos'  => ['escala_valoracion', 'rubrica'],
        'Resumen'                           => ['lista_cotejo', 'escala_valoracion'],
        'Simulación'                        => ['lista_cotejo', 'escala_valoracion', 'rubrica'],
    ];

    /** Human-readable labels for correction instrument types. */
    public const LABELS = [
        'clave_correccion'  => 'Clave de corrección',
        'lista_cotejo'      => 'Lista de cotejo',
        'escala_valoracion' => 'Escala de valoración',
        'rubrica'           => 'Rúbrica',
    ];

    /** Icons for each correction instrument type. */
    public const ICONS = [
        'clave_correccion'  => '🔑',
        'lista_cotejo'      => '✅',
        'escala_valoracion' => '📊',
        'rubrica'           => '📋',
    ];

    /** Pedagogical descriptions for each correction instrument. */
    public const DESCRIPTIONS = [
        'clave_correccion'  => 'Se asigna a instrumentos con respuestas convergentes o verificables. Incluye ítems cerrados o de respuesta única donde la corrección es objetiva y precisa.',
        'lista_cotejo'      => 'Verifica la presencia o ausencia de componentes observables. Útil en instrumentos de proceso y en productos con estructura predefinida. Constata si los elementos requeridos están o no.',
        'escala_valoracion' => 'Registra intensidad o nivel de logro sin describir exhaustivamente cada nivel. Especialmente adecuada cuando se quiere graduar el rendimiento de forma ágil y flexible.',
        'rubrica'           => 'Describe niveles de logro con descriptores detallados para cada criterio. Se reserva para evidencias complejas y multidimensionales donde el detalle agrega valor real.',
    ];

    /**
     * Get valid correction instruments for a given evaluation instrument.
     * Uses fuzzy matching to handle variations in naming from the AI.
     *
     * Returns array of ['key' => 'lista_cotejo', 'label' => '...', 'icon' => '...', 'description' => '...']
     *
     * @param string $instrument  The evaluation instrument name (from session)
     * @return array
     */
    public static function get_correction_options(string $instrument): array {
        $instrument = trim($instrument);
        if (empty($instrument)) {
            return [];
        }

        // 1. Try exact match first
        if (isset(self::ENCAJE[$instrument])) {
            return self::build_options(self::ENCAJE[$instrument]);
        }

        // 2. Fuzzy match: normalize and compare
        $norm_instrument = self::normalize($instrument);
        $best_match = null;
        $best_score = 0;

        foreach (self::ENCAJE as $canonical => $options) {
            $norm_canonical = self::normalize($canonical);

            // Exact normalized match
            if ($norm_instrument === $norm_canonical) {
                return self::build_options($options);
            }

            // Substring match (either direction)
            if (strpos($norm_canonical, $norm_instrument) !== false ||
                strpos($norm_instrument, $norm_canonical) !== false) {
                return self::build_options($options);
            }

            // Similarity score
            similar_text($norm_instrument, $norm_canonical, $pct);
            if ($pct > $best_score) {
                $best_score = $pct;
                $best_match = $options;
            }
        }

        // Accept if similarity is above 60%
        if ($best_score >= 60 && $best_match) {
            return self::build_options($best_match);
        }

        // Fallback: return all options
        return self::build_options(['lista_cotejo', 'escala_valoracion', 'rubrica']);
    }

    /**
     * Build structured option arrays from correction keys.
     */
    private static function build_options(array $keys): array {
        $result = [];
        foreach ($keys as $key) {
            $result[] = [
                'key'         => $key,
                'label'       => self::LABELS[$key] ?? $key,
                'icon'        => self::ICONS[$key] ?? '📄',
                'description' => self::DESCRIPTIONS[$key] ?? '',
            ];
        }
        return $result;
    }

    /**
     * Normalize a string for fuzzy comparison:
     * lowercase, strip accents, remove extra whitespace.
     */
    private static function normalize(string $s): string {
        $s = mb_strtolower(trim($s), 'UTF-8');
        // Remove accents
        $s = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $s
        );
        // Remove parentheses content and extra spaces
        $s = preg_replace('/\s*\(.*?\)\s*/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }
}
