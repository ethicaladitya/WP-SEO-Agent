<?php
/**
 * Content analyzer — extracts structural signals from a post's HTML content.
 *
 * Provides heading audit, FAQ detection, schema detection, entity extraction,
 * freshness signals, and content decay indicators — all without external API calls.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Content_Analyzer {

	// -------------------------------------------------------------------
	// Main entry point
	// -------------------------------------------------------------------

	/**
	 * Run a full structural analysis of a post's content.
	 *
	 * @param WP_Post $post
	 * @param array   $gsc_data   GSC metrics array (for keyword context).
	 * @return array {
	 *   string   $raw_text           Plain text without HTML.
	 *   int      $word_count
	 *   int      $sentence_count
	 *   int      $paragraph_count
	 *   array    $headings           All heading tags: [['tag'=>'h2','text'=>'...']]
	 *   string   $h1                 First H1 text.
	 *   array    $h2s                All H2 text strings.
	 *   array    $h3s                All H3 text strings.
	 *   bool     $has_faq            True if FAQ-like Q&A content detected.
	 *   array    $faq_items          Detected Q&A pairs.
	 *   bool     $has_schema         True if JSON-LD <script> blocks exist.
	 *   array    $schema_types       Detected @type values.
	 *   bool     $has_table          True if HTML <table> present.
	 *   bool     $has_list           True if <ul>/<ol> with ≥ 3 items.
	 *   bool     $has_code           True if <code>/<pre> present.
	 *   int      $image_count        Number of <img> tags.
	 *   bool     $images_missing_alt True if any <img> lacks alt text.
	 *   int      $internal_link_count
	 *   int      $external_link_count
	 *   array    $entities           Likely named entities / technical terms.
	 *   array    $years_mentioned    Years (4-digit) found in the text.
	 *   int      $freshness_score    0-100: higher = fresher signals.
	 *   bool     $content_decay_risk True if content shows staleness signals.
	 *   array    $thin_sections      H2/H3 headings with < 60 words below them.
	 *   float    $avg_words_per_section
	 *   bool     $has_intro          True if first paragraph has ≥ 40 words.
	 *   int      $readability_approx Flesch-Kincaid approximation (0-100).
	 * }
	 */
	public function analyze( WP_Post $post, array $gsc_data = array() ) {
		$html = $post->post_content;

		$raw_text  = $this->strip_to_text( $html );
		$headings  = $this->extract_headings( $html );
		$faqs      = $this->detect_faqs( $html, $raw_text );
		$schema    = $this->detect_schema( $html );
		$links     = $this->analyze_links( $html, $post );
		$images    = $this->analyze_images( $html );
		$entities  = $this->extract_entities( $raw_text, $gsc_data );
		$years     = $this->extract_years( $raw_text );
		$freshness = $this->score_freshness( $post, $years );
		$sections  = $this->find_thin_sections( $html, $headings );

		$words      = str_word_count( $raw_text );
		$sentences  = $this->count_sentences( $raw_text );
		$paragraphs = max( 1, substr_count( $raw_text, "\n\n" ) );

		$h1s = array_filter( $headings, fn( $h ) => $h['tag'] === 'h1' );
		$h2s = array_filter( $headings, fn( $h ) => $h['tag'] === 'h2' );
		$h3s = array_filter( $headings, fn( $h ) => $h['tag'] === 'h3' );

		$h2_texts = array_values( array_map( fn( $h ) => $h['text'], $h2s ) );
		$h3_texts = array_values( array_map( fn( $h ) => $h['text'], $h3s ) );

		$h1_text     = $h1s ? reset( $h1s )['text'] : '';
		$section_cnt = max( 1, count( $h2s ) ?: 1 );
		$avg_wps     = round( $words / $section_cnt, 1 );

		$first_para = $this->first_paragraph_words( $html );

		return array(
			'raw_text'              => $raw_text,
			'word_count'            => $words,
			'sentence_count'        => $sentences,
			'paragraph_count'       => $paragraphs,
			'headings'              => $headings,
			'h1'                    => $h1_text,
			'h2s'                   => $h2_texts,
			'h3s'                   => $h3_texts,
			'has_faq'               => ! empty( $faqs ),
			'faq_items'             => $faqs,
			'has_schema'            => ! empty( $schema['types'] ),
			'schema_types'          => $schema['types'],
			'has_table'             => $this->has_tag( $html, 'table' ),
			'has_list'              => $this->has_list( $html ),
			'has_code'              => $this->has_tag( $html, 'pre' ) || $this->has_tag( $html, 'code' ),
			'image_count'           => $images['count'],
			'images_missing_alt'    => $images['missing_alt'],
			'internal_link_count'   => $links['internal'],
			'external_link_count'   => $links['external'],
			'entities'              => $entities,
			'years_mentioned'       => $years,
			'freshness_score'       => $freshness['score'],
			'content_decay_risk'    => $freshness['decay_risk'],
			'thin_sections'         => $sections,
			'avg_words_per_section' => $avg_wps,
			'has_intro'             => $first_para >= 40,
			'readability_approx'    => $this->flesch_approx( $words, $sentences ),
		);
	}

	// -------------------------------------------------------------------
	// Heading extraction
	// -------------------------------------------------------------------

	private function extract_headings( $html ) {
		$headings = array();
		if ( preg_match_all( '/<(h[1-4])[^>]*>(.*?)<\/\1>/is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$headings[] = array(
					'tag'  => strtolower( $match[1] ),
					'text' => wp_strip_all_tags( $match[2] ),
				);
			}
		}
		return $headings;
	}

	// -------------------------------------------------------------------
	// FAQ detection
	// -------------------------------------------------------------------

	private function detect_faqs( $html, $raw_text ) {
		$faqs = array();

		// Pattern 1: HTML heading (h2/h3/h4) followed by a paragraph.
		// Common: "What is X?" as heading, answer as next para.
		if ( preg_match_all( '/<(h[2-4])[^>]*>([^<]*\?[^<]*)<\/\1>\s*<[^>]*>([^<]{20,300})/is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$faqs[] = array(
					'question' => trim( wp_strip_all_tags( $match[2] ) ),
					'answer'   => trim( wp_strip_all_tags( $match[3] ) ),
					'source'   => 'heading_paragraph',
				);
			}
		}

		// Pattern 2: Bold question + following text.
		if ( preg_match_all( '/<(strong|b)>([^<]{10,150}\?)<\/\1>\s*(?:<\/[^>]+>)?\s*<[^>]*>([^<]{20,300})/is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$faqs[] = array(
					'question' => trim( wp_strip_all_tags( $match[2] ) ),
					'answer'   => trim( wp_strip_all_tags( $match[3] ) ),
					'source'   => 'bold_question',
				);
			}
		}

		// Pattern 3: Explicit "FAQ" section with DL/DT/DD structure.
		if ( preg_match_all( '/<dt[^>]*>(.*?)<\/dt>\s*<dd[^>]*>(.*?)<\/dd>/is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$faqs[] = array(
					'question' => trim( wp_strip_all_tags( $match[1] ) ),
					'answer'   => trim( wp_strip_all_tags( $match[2] ) ),
					'source'   => 'dl_structure',
				);
			}
		}

		return array_slice( $faqs, 0, 10 ); // Cap at 10.
	}

	// -------------------------------------------------------------------
	// Schema detection
	// -------------------------------------------------------------------

	private function detect_schema( $html ) {
		$types  = array();
		$blocks = array();

		if ( preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m ) ) {
			foreach ( $m[1] as $json ) {
				$data = json_decode( trim( $json ), true );
				if ( ! $data ) {
					continue;
				}
				$blocks[] = $data;

				// Handle @graph arrays.
				$items = isset( $data['@graph'] ) ? $data['@graph'] : array( $data );
				foreach ( $items as $item ) {
					if ( ! empty( $item['@type'] ) ) {
						$type = is_array( $item['@type'] ) ? implode( ',', $item['@type'] ) : $item['@type'];
						$types[] = $type;
					}
				}
			}
		}

		return array(
			'types'  => array_unique( $types ),
			'blocks' => $blocks,
		);
	}

	// -------------------------------------------------------------------
	// Link analysis
	// -------------------------------------------------------------------

	private function analyze_links( $html, WP_Post $post ) {
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal  = 0;
		$external  = 0;

		if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $m ) ) {
			foreach ( $m[1] as $href ) {
				if ( strpos( $href, '#' ) === 0 || strpos( $href, 'mailto:' ) === 0 ) {
					continue;
				}
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( ! $host || $host === $site_host ) {
					$internal++;
				} else {
					$external++;
				}
			}
		}

		return array( 'internal' => $internal, 'external' => $external );
	}

	// -------------------------------------------------------------------
	// Image analysis
	// -------------------------------------------------------------------

	private function analyze_images( $html ) {
		$count       = 0;
		$missing_alt = false;

		if ( preg_match_all( '/<img[^>]*>/i', $html, $m ) ) {
			$count = count( $m[0] );
			foreach ( $m[0] as $tag ) {
				if ( ! preg_match( '/\balt=["\'][^"\']+["\']/', $tag ) ) {
					$missing_alt = true;
					break;
				}
			}
		}

		return array( 'count' => $count, 'missing_alt' => $missing_alt );
	}

	// -------------------------------------------------------------------
	// Entity extraction (NLP-lite)
	// -------------------------------------------------------------------

	/**
	 * Extract likely named entities and technical terms from content.
	 * Uses capitalization patterns and GSC query cross-referencing as signals.
	 *
	 * @param string $text      Plain-text content.
	 * @param array  $gsc_data  For query-cross-reference.
	 * @return string[]
	 */
	private function extract_entities( $text, array $gsc_data ) {
		$entities = array();

		// 1. Capitalized multi-word phrases (≥ 2 words starting with caps).
		if ( preg_match_all( '/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\b/', $text, $m ) ) {
			foreach ( $m[1] as $entity ) {
				if ( strlen( $entity ) > 3 ) {
					$entities[] = $entity;
				}
			}
		}

		// 2. Technical terms in backticks or code tags (already stripped).
		if ( preg_match_all( '/`([^`]{2,40})`/', $text, $m ) ) {
			foreach ( $m[1] as $term ) {
				$entities[] = $term;
			}
		}

		// 3. Cross-reference with GSC queries — include query words that appear in content.
		$queries = array();
		if ( ! empty( $gsc_data['queries'] ) ) {
			foreach ( $gsc_data['queries'] as $q ) {
				$queries[] = $q['query'] ?? '';
			}
		}
		foreach ( $queries as $q ) {
			if ( $q && stripos( $text, $q ) !== false ) {
				$entities[] = $q;
			}
		}

		// Deduplicate, sort by length (longer = more specific), cap at 30.
		$entities = array_unique( $entities );
		usort( $entities, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
		return array_slice( $entities, 0, 30 );
	}

	// -------------------------------------------------------------------
	// Year / freshness analysis
	// -------------------------------------------------------------------

	private function extract_years( $text ) {
		$current_year = (int) gmdate( 'Y' );
		$years        = array();

		if ( preg_match_all( '/\b(20[0-2][0-9])\b/', $text, $m ) ) {
			foreach ( $m[1] as $year ) {
				$y = (int) $year;
				if ( $y >= 2000 && $y <= $current_year + 1 ) {
					$years[] = $y;
				}
			}
		}

		return array_unique( $years );
	}

	private function score_freshness( WP_Post $post, array $years ) {
		$current_year = (int) gmdate( 'Y' );
		$modified     = strtotime( $post->post_modified );
		$age_days     = ( time() - $modified ) / DAY_IN_SECONDS;
		$score        = 100;
		$decay_risk   = false;

		// Penalise by how old the last modification is.
		if ( $age_days > 730 ) {        // > 2 years
			$score -= 40;
			$decay_risk = true;
		} elseif ( $age_days > 365 ) {  // > 1 year
			$score -= 20;
			$decay_risk = true;
		} elseif ( $age_days > 180 ) {  // > 6 months
			$score -= 10;
		}

		// Old years in content are a staleness signal.
		$old_years = array_filter( $years, fn( $y ) => ( $current_year - $y ) >= 2 );
		if ( count( $old_years ) >= 3 ) {
			$score -= 20;
			$decay_risk = true;
		} elseif ( count( $old_years ) >= 1 ) {
			$score -= 10;
		}

		// Bonus for recent modification.
		if ( $age_days < 30 ) {
			$score += 10;
		} elseif ( $age_days < 90 ) {
			$score += 5;
		}

		return array(
			'score'      => max( 0, min( 100, $score ) ),
			'decay_risk' => $decay_risk,
			'age_days'   => (int) $age_days,
		);
	}

	// -------------------------------------------------------------------
	// Thin sections
	// -------------------------------------------------------------------

	/**
	 * Find headings (H2/H3) that have fewer than $min_words of content below them.
	 *
	 * @param string $html
	 * @param array  $headings Pre-extracted headings list.
	 * @param int    $min_words
	 * @return array Heading text strings of thin sections.
	 */
	private function find_thin_sections( $html, array $headings, $min_words = 60 ) {
		$thin    = array();
		$h2h3    = array_filter( $headings, fn( $h ) => in_array( $h['tag'], array( 'h2', 'h3' ), true ) );
		$h2h3    = array_values( $h2h3 );
		$count   = count( $h2h3 );

		if ( $count < 2 ) {
			return $thin;
		}

		// Split HTML at each H2/H3 boundary and count words in each segment.
		$pattern  = '/<h[23][^>]*>.*?<\/h[23]>/is';
		$segments = preg_split( $pattern, $html );
		// segments[0] = intro, segments[1..n] = content after each heading.

		for ( $i = 0; $i < $count; $i++ ) {
			$segment_index = $i + 1;
			if ( ! isset( $segments[ $segment_index ] ) ) {
				break;
			}
			$text  = wp_strip_all_tags( $segments[ $segment_index ] );
			$words = str_word_count( $text );
			if ( $words < $min_words ) {
				$thin[] = array(
					'heading'    => $h2h3[ $i ]['text'],
					'word_count' => $words,
				);
			}
		}

		return $thin;
	}

	// -------------------------------------------------------------------
	// Misc helpers
	// -------------------------------------------------------------------

	private function strip_to_text( $html ) {
		$text = wp_strip_all_tags( $html );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	private function has_tag( $html, $tag ) {
		return (bool) preg_match( '/<' . preg_quote( $tag, '/' ) . '[\s>]/i', $html );
	}

	private function has_list( $html ) {
		if ( preg_match_all( '/<li[^>]*>/i', $html, $m ) ) {
			return count( $m[0] ) >= 3;
		}
		return false;
	}

	private function count_sentences( $text ) {
		$count = preg_match_all( '/[.!?]+(?:\s|$)/', $text );
		return max( 1, (int) $count );
	}

	private function first_paragraph_words( $html ) {
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $html, $m ) ) {
			return str_word_count( wp_strip_all_tags( $m[1] ) );
		}
		return 0;
	}

	/**
	 * Approximate Flesch Reading Ease (0 = unreadable, 100 = very easy).
	 * Simplified: uses avg sentence length only (no syllable counting).
	 */
	private function flesch_approx( $words, $sentences ) {
		if ( $sentences === 0 ) {
			return 50;
		}
		$asl   = $words / $sentences; // Average sentence length.
		// Simplified Flesch: 206.835 − (1.015 × ASL) − assume avg syllables ≈ 1.5 per word.
		$score = 206.835 - ( 1.015 * $asl ) - ( 84.6 * 1.5 );
		return (int) max( 0, min( 100, $score ) );
	}
}
