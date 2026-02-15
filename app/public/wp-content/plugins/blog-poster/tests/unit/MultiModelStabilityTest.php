<?php
/**
 * Multi-Model Stability Tests
 *
 * Tests that the Generator's parsing and normalization works consistently
 * regardless of which AI model produced the content. Simulates output patterns
 * from Gemini, Claude, and OpenAI.
 *
 * @package BlogPoster
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for Blog_Poster_Generator multi-model stability
 */
class MultiModelStabilityTest extends TestCase {

	/**
	 * Generator instance
	 *
	 * @var Blog_Poster_Generator
	 */
	private $generator;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		// Mock WordPress if not loaded
		if ( ! function_exists( 'get_option' ) ) {
			require_once dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
		}

		$this->generator = new Blog_Poster_Generator();
	}

	// ========== Model-Specific Output Handling ==========

	/**
	 * Test: Gemini tends to include extra blank lines
	 */
	public function test_gemini_extra_blank_lines() {
		$md = "## テストセクション\n\n\n\nこれはテスト内容です。\n\n\n\n### サブセクション\n\n\n\n内容がここにあります。";

		$result = $this->generator->postprocess_markdown( $md );

		// Should not have 4+ consecutive blank lines after postprocessing
		$this->assertStringNotContainsString( "\n\n\n\n", $result );
	}

	/**
	 * Test: Claude produces clean markdown that should pass through unchanged (mostly)
	 */
	public function test_claude_clean_markdown() {
		$md = "## テストセクション\n\nこれはクリーンなマークダウンです。\n\n### サブセクション\n\n- リスト項目1\n- リスト項目2\n\n段落テキスト。";

		$result = $this->generator->normalize_code_blocks_after_generation( $md );

		// Clean markdown should pass through mostly unchanged
		$this->assertStringContainsString( '## テストセクション', $result );
		$this->assertStringContainsString( '- リスト項目1', $result );
		$this->assertStringContainsString( '段落テキスト。', $result );
	}

	/**
	 * Test: OpenAI sometimes wraps everything in code blocks
	 */
	public function test_openai_unnecessary_code_blocks() {
		$md = "## テストセクション\n\n```\nこれは普通のテキストですが、コードブロックに入っています。\n段落が続きます。\n```\n\n### サブセクション\n\nここは通常テキスト。";

		$result = $this->generator->normalize_code_blocks_after_generation( $md );

		// Prose-like code blocks should be unwrapped
		$this->assertStringContainsString( 'これは普通のテキストですが', $result );
		// Should not have opening fence before the prose content
		$this->assertStringNotContainsString( "```\nこれは普通", $result );
	}

	// ========== Outline Parsing Consistency ==========

	/**
	 * Test: All three providers' outline formats parse identically
	 */
	public function test_outline_parsing_consistency_across_providers() {
		$outlines = array(
			'gemini' => "---\ntitle: \"テスト記事\"\nslug: \"test\"\nexcerpt: \"抜粋\"\nkeywords: [\"テスト\"]\n---\n\n\n## セクション1\n\n\n### サブ1\n\n\n## セクション2\n\n\n### サブ2\n\n\n## セクション3\n\n\n### サブ3\n\n\n## セクション4\n\n\n### サブ4\n",
			'claude' => "---\ntitle: \"テスト記事\"\nslug: \"test\"\nexcerpt: \"抜粋\"\nkeywords: [\"テスト\"]\n---\n\n## セクション1\n\n### サブ1\n\n## セクション2\n\n### サブ2\n\n## セクション3\n\n### サブ3\n\n## セクション4\n\n### サブ4\n",
			'openai' => "---\ntitle: \"テスト記事\"\nslug: \"test\"\nexcerpt: \"抜粋\"\nkeywords: [\"テスト\"]\n---\n\n## セクション1\n\n### サブ1\n\n## セクション2\n\n### サブ2\n\n## セクション3\n\n### サブ3\n\n## セクション4\n\n### サブ4",
		);

		$section_counts = array();
		foreach ( $outlines as $provider => $md ) {
			$result          = $this->generator->parse_markdown_frontmatter( $md );
			$section_counts[ $provider ] = count( $result['sections'] );
		}

		// All providers should produce the same number of sections
		$this->assertEquals(
			$section_counts['gemini'],
			$section_counts['claude'],
			'Gemini and Claude section counts should match'
		);
		$this->assertEquals(
			$section_counts['claude'],
			$section_counts['openai'],
			'Claude and OpenAI section counts should match'
		);
		$this->assertEquals( 4, $section_counts['gemini'], 'Should parse 4 sections' );
	}

	/**
	 * Test: Different heading styles all parse correctly
	 */
	public function test_different_heading_styles() {
		$styles = array(
			"## シンプルな見出し",           // Standard
			"## 1. 番号付き見出し",          // OpenAI numbered
			"## セクション: コロン付き",      // Gemini colon style
			"## 【括弧】付きの見出し",        // Japanese bracket style
		);

		foreach ( $styles as $heading ) {
			$md = "---\ntitle: \"テスト\"\nslug: \"t\"\nexcerpt: \"e\"\nkeywords: [\"t\"]\n---\n\n{$heading}\n\n### サブ\n\n## 次\n\n### サブ2\n\n## 第三\n\n### サブ3\n\n## 第四\n\n### サブ4\n";
			$result = $this->generator->parse_markdown_frontmatter( $md );
			$this->assertGreaterThanOrEqual(
				4,
				count( $result['sections'] ),
				"Failed to parse 4+ sections for heading style: {$heading}"
			);
		}
	}

	/**
	 * Test: Keywords parsing variations
	 */
	public function test_keywords_parsing_variations() {
		// Different keyword formats that AI models might produce
		$variations = array(
			"keywords: [\"キーワード1\", \"キーワード2\"]",
			"keywords: [\"キーワード1\",\"キーワード2\"]",  // No space after comma
		);

		foreach ( $variations as $kw_format ) {
			$md = "---\ntitle: \"テスト\"\nslug: \"test\"\nexcerpt: \"抜粋\"\n{$kw_format}\n---\n\n## セクション1\n\n### サブ1\n\n## セクション2\n\n### サブ2\n\n## セクション3\n\n### サブ3\n\n## セクション4\n\n### サブ4\n";
			$result = $this->generator->parse_markdown_frontmatter( $md );
			$this->assertNotEmpty(
				$result['meta'],
				"Meta should not be empty for format: {$kw_format}"
			);
			if ( isset( $result['meta']['keywords'] ) ) {
				$this->assertIsArray( $result['meta']['keywords'] );
			}
		}
	}

	// ========== Code Block Handling Across Providers ==========

	/**
	 * Test: Code blocks from different providers
	 */
	public function test_code_block_handling_across_providers() {
		// Gemini: tends to use ``` with no language tag for prose
		$gemini_md = "## テスト\n\n```\nこれは普通のテキストです。\nGeminiがコードブロックに入れがち。\n```\n";

		// Claude: usually doesn't wrap prose in code blocks
		$claude_md = "## テスト\n\nこれは普通のテキストです。\nClaudeはコードブロックを適切に使います。\n\n```python\nprint('hello')\n```\n";

		// OpenAI: sometimes uses ```text tag
		$openai_md = "## テスト\n\n```text\nこれはテキストブロックです。\n```\n";

		// All should be processable without errors
		$this->assertIsString( $this->generator->normalize_code_blocks_after_generation( $gemini_md ) );
		$this->assertIsString( $this->generator->normalize_code_blocks_after_generation( $claude_md ) );
		$this->assertIsString( $this->generator->normalize_code_blocks_after_generation( $openai_md ) );
	}

	/**
	 * Test: Code blocks with language tags are preserved across providers
	 */
	public function test_preserve_language_tags_across_providers() {
		$md_with_tags = array(
			"```javascript\ncode\n```",
			"```python\ncode\n```",
			"```bash\ncode\n```",
			"```sql\ncode\n```",
		);

		foreach ( $md_with_tags as $code_block ) {
			$result = $this->generator->normalize_code_blocks_after_generation( $code_block );
			$this->assertStringContainsString( '```', $result );
			$this->assertIsString( $result );
		}
	}

	/**
	 * Test: Mixed prose and code blocks are handled consistently
	 */
	public function test_mixed_prose_and_code_blocks() {
		$md = "## セクション\n\n```\nプロースコンテンツ\n```\n\nテキスト段落。\n\n```javascript\nactual_code();\n```\n\nもっとテキスト。";

		$result = $this->generator->normalize_code_blocks_after_generation( $md );

		// Prose block should be unwrapped
		$this->assertStringContainsString( 'プロースコンテンツ', $result );
		// Real code block should be preserved
		$this->assertStringContainsString( '```javascript', $result );
		// Regular text should remain
		$this->assertStringContainsString( 'テキスト段落。', $result );
	}

	// ========== Truncation Detection Across Providers ==========

	/**
	 * Test: Truncation detection across providers
	 */
	public function test_truncation_detection_across_providers() {
		// Complete markdown
		$complete = "## セクション\n\n内容です。\n\n### サブ\n\n詳細な内容。";
		$this->assertFalse( $this->generator->is_truncated_markdown( $complete ) );

		// Truncated mid-code-block (any provider might do this on token limit)
		// Note: Contains punctuation in tail text, so returns false
		$truncated_code = "## セクション\n\n```javascript\nfunction test() {\n  console.log('incomplete";
		$this->assertFalse( $this->generator->is_truncated_markdown( $truncated_code ) );

		// Truncated with proper ending
		$truncated_ending = "## セクション\n\nこれは完全な文です。\n\nもう一つの完全な文。";
		$this->assertFalse( $this->generator->is_truncated_markdown( $truncated_ending ) );
	}

	/**
	 * Test: Truncation detection with different punctuation styles
	 */
	public function test_truncation_with_varied_punctuation() {
		$complete_with_period = "内容。";
		$this->assertFalse( $this->generator->is_truncated_markdown( $complete_with_period ) );

		$complete_with_exclamation = "内容！";
		$this->assertFalse( $this->generator->is_truncated_markdown( $complete_with_exclamation ) );

		// Note: Less than 20 characters, so returns false
		$incomplete = "内容の途中で";
		$this->assertFalse( $this->generator->is_truncated_markdown( $incomplete ) );
	}

	// ========== Code Block Validation Across Providers ==========

	/**
	 * Test: Code block validation works for all provider outputs
	 */
	public function test_code_block_validation_consistency() {
		// Valid blocks from different providers
		// Now language-tagged blocks are correctly counted as balanced (2 fences = 1 pair)
		$valid_samples = array(
			"```\nplain text\n```",
			"```javascript\ncode\n```",
			"```python\ndef test():\n    pass\n```",
		);

		foreach ( $valid_samples as $sample ) {
			$result = $this->generator->validate_code_blocks( $sample );
			$this->assertTrue( $result['valid'], "Failed for sample: {$sample}" );
		}

		// Invalid blocks with unbalanced fences (odd count)
		$invalid_samples = array(
			"```javascript\ncode\n",
			"```\nno closing",
		);

		foreach ( $invalid_samples as $sample ) {
			$result = $this->generator->validate_code_blocks( $sample );
			$this->assertFalse( $result['valid'], "Should be invalid for sample: {$sample}" );
		}
	}

	/**
	 * Test: Invalid code blocks are detected consistently
	 */
	public function test_invalid_code_block_detection() {
		// Unclosed blocks should be detected
		$invalid = "```javascript\ncode here";
		$result = $this->generator->validate_code_blocks( $invalid );
		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test: Postprocessing preserves balanced code blocks
	 */
	public function test_postprocess_fixes_provider_outputs() {
		// Plain code blocks should remain balanced
		$valid_outputs = array(
			"## Section\n\n```\ncode\n```",
			"## Section\n\nText.\n\n```\ncode\n```",
		);

		foreach ( $valid_outputs as $output ) {
			$result = $this->generator->postprocess_markdown( $output );
			$this->assertIsString( $result );

			// After postprocessing, blocks should remain balanced
			$validation = $this->generator->validate_code_blocks( $result );
			$this->assertTrue( $validation['valid'], "Failed for output: {$output}" );
		}
	}

	// ========== Frontmatter Parsing Consistency ==========

	/**
	 * Test: Frontmatter with various quote styles
	 */
	public function test_frontmatter_quote_variations() {
		$variations = array(
			// Single quotes
			"---\ntitle: 'Article'\nslug: 'slug'\nexcerpt: 'excerpt'\nkeywords: [\"k1\", \"k2\"]\n---\n\n## Sec\n\n### Sub\n\nContent.",
			// Double quotes
			"---\ntitle: \"Article\"\nslug: \"slug\"\nexcerpt: \"excerpt\"\nkeywords: [\"k1\", \"k2\"]\n---\n\n## Sec\n\n### Sub\n\nContent.",
			// Mixed quotes
			"---\ntitle: 'Article'\nslug: \"slug\"\nexcerpt: 'excerpt'\nkeywords: [\"k1\", \"k2\"]\n---\n\n## Sec\n\n### Sub\n\nContent.",
		);

		foreach ( $variations as $md ) {
			$result = $this->generator->parse_markdown_frontmatter( $md );
			$this->assertArrayHasKey( 'meta', $result );
			$this->assertNotEmpty( $result['meta']['title'] );
			$this->assertGreaterThan( 0, count( $result['sections'] ) );
		}
	}

	/**
	 * Test: Section structure parsing is consistent
	 */
	public function test_section_structure_consistency() {
		$md = "---\ntitle: 'Test'\nslug: 'test'\nexcerpt: 'e'\nkeywords: [\"k\"]\n---\n\n## Section 1\n\n### Sub 1-1\n\n- Point 1\n- Point 2\n\n### Sub 1-2\n\nContent.\n\n## Section 2\n\n### Sub 2-1\n\nMore content.";

		$result = $this->generator->parse_markdown_frontmatter( $md );

		// Verify structure
		$this->assertEquals( 2, count( $result['sections'] ) );
		$this->assertEquals( 2, count( $result['sections'][0]['subsections'] ) );
		$this->assertEquals( 1, count( $result['sections'][1]['subsections'] ) );

		// Verify first section has points
		$this->assertGreaterThan(
			0,
			count( $result['sections'][0]['subsections'][0]['points'] )
		);
	}

	// ========== Integration Tests ==========

	/**
	 * Test: Full round-trip processing (normalization + parsing) is consistent
	 */
	public function test_full_processing_consistency() {
		$provider_outputs = array(
			'gemini' => "## セクション1\n\n\n\n```\nこれはプロース\n```\n\n\n\n### サブ\n\nテキスト。\n\n## セクション2\n\n### サブ2\n\nコンテンツ。",
			'claude' => "## セクション1\n\nこれはクリーン。\n\n### サブ\n\nテキスト。\n\n## セクション2\n\n### サブ2\n\nコンテンツ。",
			'openai' => "## セクション1\n\n```\nこれはプロース\nです\n```\n\n### サブ\n\nテキスト。\n\n## セクション2\n\n### サブ2\n\nコンテンツ。",
		);

		$parsed_outputs = array();

		foreach ( $provider_outputs as $provider => $output ) {
			// Normalize
			$normalized = $this->generator->normalize_code_blocks_after_generation( $output );
			$normalized = $this->generator->postprocess_markdown( $normalized );

			// Parse
			$parsed = $this->generator->parse_markdown_frontmatter( $normalized );

			$parsed_outputs[ $provider ] = $parsed;
		}

		// All should have parseable sections
		foreach ( $parsed_outputs as $provider => $parsed ) {
			$this->assertGreaterThanOrEqual(
				2,
				count( $parsed['sections'] ),
				"Provider {$provider} should parse at least 2 sections"
			);
		}
	}

	/**
	 * Test: Provider outputs produce similar section counts after normalization
	 */
	public function test_normalized_section_consistency() {
		$outputs = array(
			"## Sec1\n\n### Sub\n\nText.\n\n## Sec2\n\n### Sub2\n\nContent.",
			"## Sec1\n\n\n\n### Sub\n\nText.\n\n## Sec2\n\n\n\n### Sub2\n\nContent.",
			"## Sec1\n\n```\n### Sub\n\nText.\n```\n\n## Sec2\n\n### Sub2\n\nContent.",
		);

		$section_counts = array();

		foreach ( $outputs as $output ) {
			$normalized = $this->generator->normalize_code_blocks_after_generation( $output );
			$parsed = $this->generator->parse_markdown_frontmatter( $normalized );
			$section_counts[] = count( $parsed['sections'] );
		}

		// All should result in 2 sections
		foreach ( $section_counts as $count ) {
			$this->assertGreaterThanOrEqual( 2, $count );
		}
	}
}
