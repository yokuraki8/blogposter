<?php
/**
 * SEO analysis for Blog Poster.
 *
 * @package BlogPoster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blog_Poster_SEO_Analyzer {

    public function analyze_comprehensive( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return array();
        }

        $content = $post->post_content;
        $structure = $this->analyze_structure( $content );
        $seo = $this->analyze_seo( $post_id, $content );
        $engagement = $this->analyze_engagement( $content );
        $trust = $this->analyze_trust( $content );

        $overall = $this->build_overall_score( $structure, $seo, $engagement, $trust );
        $recommendations = $this->build_recommendations( $structure, $seo, $engagement, $trust );

        return array(
            'version' => '1.0',
            'analyzed_at' => time(),
            'analyzed_by' => 'heuristic',
            'structure' => $structure,
            'seo' => $seo,
            'engagement' => $engagement,
            'trust' => $trust,
            'overall' => $overall,
            'recommendations' => $recommendations,
        );
    }

    public function analyze_structure( $content ) {
        $headings = $this->count_headings( $content );
        $paragraphs = $this->extract_paragraphs( $content );

        $lead_length = isset( $paragraphs[0] ) ? mb_strlen( $paragraphs[0] ) : 0;
        $lead_present = $lead_length >= 80;

        $conclusion_present = $this->has_conclusion_heading( $content );

        $paragraph_avg = 0;
        if ( ! empty( $paragraphs ) ) {
            $sum = 0;
            foreach ( $paragraphs as $p ) {
                $sum += mb_strlen( $p );
            }
            $paragraph_avg = (int) round( $sum / count( $paragraphs ) );
        }

        $word_count = mb_strlen( wp_strip_all_tags( $content ) );
        $reading_time = max( 1, (int) ceil( $word_count / 400 ) );

        $score = 100;
        if ( $headings['h2_count'] < 3 ) {
            $score -= 15;
        }
        if ( ! $lead_present ) {
            $score -= 10;
        }
        if ( ! $conclusion_present ) {
            $score -= 10;
        }

        return array(
            'score' => max( 0, $score ),
            'heading_hierarchy' => array(
                'h1_count' => $headings['h1_count'],
                'h2_count' => $headings['h2_count'],
                'h3_count' => $headings['h3_count'],
                'issues' => array(),
            ),
            'lead_paragraph' => array(
                'present' => $lead_present,
                'length' => $lead_length,
                'target_length' => '150-300',
            ),
            'conclusion' => array(
                'present' => $conclusion_present,
            ),
            'paragraph_avg_length' => $paragraph_avg,
            'word_count' => $word_count,
            'reading_time_minutes' => $reading_time,
        );
    }

    public function analyze_seo( $post_id, $content ) {
        $title = get_the_title( $post_id );
        $title_len = mb_strlen( $title );
        $title_status = $this->length_status( $title_len, 30, 60 );

        $meta = get_post_meta( $post_id, '_blog_poster_meta_description', true );
        if ( empty( $meta ) ) {
            $meta = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        }
        $meta_len = mb_strlen( (string) $meta );
        $meta_status = $this->length_status( $meta_len, 120, 160 );

        $keywords = $this->get_primary_keyword( $post_id );
        $keyword_info = array(
            'keyword' => $keywords,
            'density' => 0,
            'status' => 'missing',
            'count' => 0,
        );
        if ( $keywords ) {
            $count = $this->count_occurrences( $content, $keywords );
            $total = max( 1, mb_strlen( wp_strip_all_tags( $content ) ) );
            $density = round( ( $count * mb_strlen( $keywords ) ) / $total * 100, 2 );
            $keyword_info = array(
                'keyword' => $keywords,
                'density' => $density,
                'status' => $density >= 0.5 && $density <= 3.0 ? 'ok' : 'low',
                'count' => $count,
            );
        }

        $links = $this->count_links( $content );

        $score = 100;
        if ( 'too_short' === $title_status || 'too_long' === $title_status ) {
            $score -= 10;
        }
        if ( 'too_short' === $meta_status || 'too_long' === $meta_status ) {
            $score -= 10;
        }
        if ( $links['internal'] < 3 ) {
            $score -= 5;
        }
        if ( $links['external'] < 1 ) {
            $score -= 5;
        }

        return array(
            'score' => max( 0, $score ),
            'title' => array(
                'length' => $title_len,
                'status' => $title_status,
                'target_range' => '30-60',
            ),
            'meta_description' => array(
                'length' => $meta_len,
                'status' => $meta_status,
                'target_range' => '120-160',
                'click_appeal_score' => 65,
            ),
            'keywords' => array(
                'primary' => $keyword_info,
                'secondary' => array(),
            ),
            'internal_links' => array(
                'count' => $links['internal'],
                'target_min' => 3,
            ),
            'external_links' => array(
                'count' => $links['external'],
                'target_min' => 1,
            ),
        );
    }

    public function analyze_engagement( $content ) {
        $paragraphs = $this->extract_paragraphs( $content );
        $first = isset( $paragraphs[0] ) ? $paragraphs[0] : '';

        $hook_present = ( false !== mb_strpos( $first, '？' ) || false !== mb_strpos( $first, '?' ) );
        $cta_count = $this->count_cta( $content );
        $code_blocks = $this->count_code_blocks( $content );
        $numeric = $this->count_numbers( $content );

        $score = 100;
        if ( ! $hook_present ) {
            $score -= 15;
        }
        if ( $cta_count === 0 ) {
            $score -= 10;
        }

        return array(
            'score' => max( 0, $score ),
            'hook' => array(
                'present' => $hook_present,
                'type' => $hook_present ? 'question' : null,
            ),
            'cta' => array(
                'present' => $cta_count > 0,
                'count' => $cta_count,
                'in_conclusion' => false,
            ),
            'concrete_examples' => array(
                'score' => min( 100, 40 + $numeric * 5 ),
                'data_count' => $numeric,
                'code_blocks' => $code_blocks,
            ),
            'formatting' => array(
                'lists' => $this->count_lists( $content ),
                'blockquotes' => $this->count_blockquotes( $content ),
                'bold_count' => $this->count_bold( $content ),
            ),
        );
    }

    public function analyze_trust( $content ) {
        $external = $this->count_links( $content )['external'];
        $numeric = $this->count_numbers( $content );
        $terms = $this->count_technical_terms( $content );

        $score = 100;
        if ( $external === 0 ) {
            $score -= 15;
        }
        if ( $terms < 3 ) {
            $score -= 5;
        }

        return array(
            'score' => max( 0, $score ),
            'citations' => array(
                'count' => $external,
                'sources' => array(),
            ),
            'numeric_data' => array(
                'present' => $numeric > 0,
                'count' => $numeric,
            ),
            'technical_terms' => array(
                'count' => $terms,
                'explained' => 0,
            ),
        );
    }

    public function save_analysis( $post_id, $analysis ) {
        update_post_meta( $post_id, '_blog_poster_seo_analysis', $analysis );
    }

    public function get_analysis( $post_id ) {
        return get_post_meta( $post_id, '_blog_poster_seo_analysis', true );
    }

    private function count_headings( $content ) {
        return array(
            'h1_count' => preg_match_all( '/<h1\b[^>]*>/i', $content, $m1 ),
            'h2_count' => preg_match_all( '/<h2\b[^>]*>/i', $content, $m2 ),
            'h3_count' => preg_match_all( '/<h3\b[^>]*>/i', $content, $m3 ),
        );
    }

    private function extract_paragraphs( $content ) {
        preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $content, $matches );
        $paragraphs = array();
        foreach ( $matches[1] as $p ) {
            $text = trim( wp_strip_all_tags( $p ) );
            if ( $text !== '' ) {
                $paragraphs[] = $text;
            }
        }
        return $paragraphs;
    }

    private function has_conclusion_heading( $content ) {
        return (bool) preg_match( '/<h[2-3][^>]*>\s*(まとめ|結論|総括)/iu', $content );
    }

    private function length_status( $len, $min, $max ) {
        if ( $len === 0 ) {
            return 'missing';
        }
        if ( $len < $min ) {
            return 'too_short';
        }
        if ( $len > $max ) {
            return 'too_long';
        }
        return 'ok';
    }

    private function get_primary_keyword( $post_id ) {
        $keywords = get_post_meta( $post_id, '_blog_poster_keywords', true );
        if ( empty( $keywords ) ) {
            return '';
        }
        $parts = array_map( 'trim', explode( ',', $keywords ) );
        return isset( $parts[0] ) ? $parts[0] : '';
    }

    private function count_occurrences( $content, $keyword ) {
        $text = wp_strip_all_tags( $content );
        return substr_count( $text, $keyword );
    }

    private function count_links( $content ) {
        preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\']/i', $content, $matches );
        $internal = 0;
        $external = 0;
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        foreach ( $matches[1] as $href ) {
            $link_host = wp_parse_url( $href, PHP_URL_HOST );
            if ( empty( $link_host ) || $link_host === $host ) {
                $internal++;
            } else {
                $external++;
            }
        }
        return array( 'internal' => $internal, 'external' => $external );
    }

    private function count_cta( $content ) {
        $patterns = array( '問い合わせ', 'お問い合わせ', '申し込', '購入', '登録', 'ダウンロード', 'ください' );
        $text = wp_strip_all_tags( $content );
        $count = 0;
        foreach ( $patterns as $p ) {
            $count += substr_count( $text, $p );
        }
        return $count;
    }

    private function count_code_blocks( $content ) {
        return preg_match_all( '/<pre\b[^>]*>/i', $content, $m );
    }

    private function count_numbers( $content ) {
        return preg_match_all( '/\d+/', wp_strip_all_tags( $content ), $m );
    }

    private function count_lists( $content ) {
        return preg_match_all( '/<(ul|ol)\b[^>]*>/i', $content, $m );
    }

    private function count_blockquotes( $content ) {
        return preg_match_all( '/<blockquote\b[^>]*>/i', $content, $m );
    }

    private function count_bold( $content ) {
        return preg_match_all( '/<(strong|b)\b[^>]*>/i', $content, $m );
    }

    private function count_technical_terms( $content ) {
        $terms = array( 'API', 'SEO', 'RAG', 'LLM', 'アルゴリズム', '機械学習', 'データ', '統計', 'インデックス' );
        $text = wp_strip_all_tags( $content );
        $count = 0;
        foreach ( $terms as $t ) {
            $count += substr_count( $text, $t );
        }
        return $count;
    }

    private function build_overall_score( $structure, $seo, $engagement, $trust ) {
        $score = ( $structure['score'] * 0.3 ) + ( $seo['score'] * 0.3 ) + ( $engagement['score'] * 0.2 ) + ( $trust['score'] * 0.2 );
        $score = (int) round( $score );

        $grade = 'C';
        if ( $score >= 85 ) {
            $grade = 'A';
        } elseif ( $score >= 70 ) {
            $grade = 'B';
        } elseif ( $score >= 55 ) {
            $grade = 'C';
        } elseif ( $score >= 40 ) {
            $grade = 'D';
        } else {
            $grade = 'F';
        }

        return array(
            'composite_score' => $score,
            'grade' => $grade,
        );
    }

    private function build_recommendations( $structure, $seo, $engagement, $trust ) {
        $recs = array();
        $id = 1;

        if ( ! $structure['lead_paragraph']['present'] ) {
            $recs[] = $this->make_rec( $id++, 1, 'structure', 'missing_lead', 'リード文を追加', '記事の冒頭にリード文（150-300文字）を追加してください', 'high' );
        }
        if ( ! $structure['conclusion']['present'] ) {
            $recs[] = $this->make_rec( $id++, 2, 'structure', 'missing_conclusion', '結論セクションを追加', '記事末尾にまとめ（200-400文字）を追加してください', 'high' );
        }
        if ( $seo['internal_links']['count'] < $seo['internal_links']['target_min'] ) {
            $recs[] = $this->make_rec( $id++, 2, 'seo', 'internal_links', '内部リンクを追加', '内部リンクを' . $seo['internal_links']['target_min'] . '件以上に増やしてください', 'medium' );
        }
        if ( $seo['external_links']['count'] < $seo['external_links']['target_min'] ) {
            $recs[] = $this->make_rec( $id++, 3, 'seo', 'external_links', '外部リンクを追加', '参考文献として外部リンクを追加してください', 'medium' );
        }
        if ( $engagement['cta']['count'] === 0 ) {
            $recs[] = $this->make_rec( $id++, 3, 'engagement', 'missing_cta', 'CTAを追加', '記事内に行動喚起を追加してください', 'medium' );
        }

        return $recs;
    }

    private function make_rec( $id, $priority, $category, $type, $title, $desc, $impact ) {
        return array(
            'id' => 'rec_' . str_pad( (string) $id, 3, '0', STR_PAD_LEFT ),
            'priority' => $priority,
            'category' => $category,
            'type' => $type,
            'title' => $title,
            'description' => $desc,
            'impact' => $impact,
        );
    }
}

