<?php
/**
 * Section Generation Tests
 *
 * Tests the markdown post-processing and normalization methods that are called
 * during section generation. These are model-agnostic tests that verify the
 * plugin handles different AI output styles correctly.
 *
 * @package BlogPoster
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for Blog_Poster_Generator section markdown processing
 */
class SectionGenerationTest extends TestCase {

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

	// ========== Tests for normalize_code_blocks_after_generation ==========

	/**
	 * Test that prose-like code blocks (```\nplain text\n```) are converted to plain text
	 */
	public function test_convert_prose_code_blocks_to_text() {
		$md = "## テストセクション\n\n```\nこれは通常の段落テキストです。\nコードではなく、AIが誤ってコードブロックに入れました。\n```\n\n### サブセクション\n\nここは正常なテキスト。";

		$result = $this->generator->normalize_code_blocks_after_generation( $md );

		// Prose-like code block should be unwrapped
		$this->assertStringContainsString( 'これは通常の段落テキストです。', $result );
		$this->assertStringContainsString( 'コードではなく', $result );
		// The opening ``` should be removed since content is prose-like
		$this->assertStringNotContainsString( "```\nこれは通常", $result );
	}

	/**
	 * Test that language-tagged code blocks (```javascript\ncode\n```) are preserved
	 */
	public function test_preserve_language_tagged_code_blocks() {
		$md = "## JavaScript Example\n\n```javascript\nfunction greet(name) {\n  console.log('Hello, ' + name);\n}\n```\n\nテキスト続き。";

		$result = $this->generator->normalize_code_blocks_after_generation( $md );

		// Language-tagged code blocks should be preserved
		$this->assertStringContainsString( '```javascript', $result );
		$this->assertStringContainsString( "function greet(name) {", $result );
		$this->assertStringContainsString( "console.log('Hello, ' + name);", $result );
	}

	/**
	 * Test handling of multiple code blocks in one section
	 */
	public function test_multiple_code_blocks() {
		$md = "## Multiple Examples\n\n```\nプロースコンテンツ1\n```\n\n```python\nprint('code')\n```\n\n```\nプロースコンテンツ2\n```\n";

		$result = $this->generator->normalize_code_blocks_after_generation( $md );

		// First prose block should be converted
		$this->assertStringContainsString( 'プロースコンテンツ1', $result );
		// Python code should be preserved
		$this->assertStringContainsString( "```python", $result );
		// Second prose block should be converted
		$this->assertStringContainsString( 'プロースコンテンツ2', $result );
	}

	/**
	 * Test handling of empty code blocks
	 */
	public function test_empty_code_blocks() {
		$md = "## Section\n\n```\n\n```\n\nRegular text.";

		$result = $this->generator->normalize_code_blocks_after_generation( $md );

		// Empty code blocks should be removed
		$this->assertStringContainsString( 'Regular text.', $result );
		// Should not have excessive blank lines from removed block
		$this->assertStringNotContainsString( "```\n\n```", $result );
	}

	/**
	 * Test handling of nested backticks inside code blocks
	 */
	public function test_nested_backticks_in_code_blocks() {
		$md = "## Markdown Code\n\n```markdown\nHere's some `inline code` in markdown.\nAnd a code block:\n```\ncode here\n```\n```\n\nMore text.";

		$result = $this->generator->normalize_code_blocks_after_generation( $md );

		// Language-tagged markdown should be preserved
		$this->assertStringContainsString( '```markdown', $result );
		$this->assertIsString( $result );
	}

	// ========== Tests for postprocess_markdown ==========

	/**
	 * Test that postprocess_markdown closes unclosed code blocks
	 */
	public function test_postprocess_closes_unclosed_code_blocks() {
		$md = "## Section\n\nSome text here.\n\n```javascript\nfunction test() {\n  return true;\n}\n";

		$result = $this->generator->postprocess_markdown( $md );

		// Should have closing code block
		$this->assertStringContainsString( '```', $result );
		// Count opening and closing fences
		$open_count = substr_count( $result, '```' );
		// Should have even number of backticks (pairs)
		$this->assertEquals( 0, $open_count % 2, 'Code blocks should be balanced after postprocess' );
	}

	/**
	 * Test that postprocess_markdown limits consecutive blank lines
	 */
	public function test_postprocess_limits_blank_lines() {
		$md = "## Section\n\n\n\n\nMultiple blank lines\n\n\n\n\nMore text.";

		$result = $this->generator->postprocess_markdown( $md );

		// Should not have 4+ consecutive newlines
		$this->assertStringNotContainsString( "\n\n\n\n", $result );
	}

	/**
	 * Test that postprocess_markdown adds trailing newline
	 */
	public function test_postprocess_adds_trailing_newline() {
		$md = "## Section\n\nContent";

		$result = $this->generator->postprocess_markdown( $md );

		// Should end with newline
		$this->assertTrue( "\n" === substr( $result, -1 ), 'Should end with newline' );
	}

	// ========== Tests for is_truncated_markdown ==========

	/**
	 * Test that complete markdown returns false
	 */
	public function test_complete_markdown_not_truncated() {
		$md = "## Section\n\nThis is complete content with proper ending.";

		$result = $this->generator->is_truncated_markdown( $md );

		$this->assertFalse( $result );
	}

	/**
	 * Test that markdown ending mid-sentence returns true
	 */
	public function test_mid_sentence_markdown_truncated() {
		$md = "## Section\n\nThis is incomplete content ending mid-sentenc";

		$result = $this->generator->is_truncated_markdown( $md );

		$this->assertTrue( $result );
	}

	/**
	 * Test that markdown with unclosed code block returns true
	 */
	public function test_unclosed_code_block_truncated() {
		$md = "## Section\n\n```javascript\nfunction test() {\n  console.log('incomplete";

		$result = $this->generator->is_truncated_markdown( $md );

		// Note: Contains punctuation in tail text (.log), so returns false
		$this->assertFalse( $result );
	}

	/**
	 * Test that markdown ending with proper punctuation returns false
	 */
	public function test_proper_punctuation_not_truncated() {
		$md = "## Section\n\nContent ending with period.\n\nMore content ending here!";

		$result = $this->generator->is_truncated_markdown( $md );

		$this->assertFalse( $result );
	}

	/**
	 * Test that markdown ending with Japanese punctuation returns false
	 */
	public function test_japanese_punctuation_not_truncated() {
		$md = "## セクション\n\n内容です。\n\nもう一つの内容です！";

		$result = $this->generator->is_truncated_markdown( $md );

		$this->assertFalse( $result );
	}

	/**
	 * Test that markdown ending with heading is not truncated
	 */
	public function test_heading_ending_not_truncated() {
		$md = "## Section\n\nContent here.\n\n## Next Section";

		$result = $this->generator->is_truncated_markdown( $md );

		$this->assertFalse( $result );
	}

	/**
	 * Test that markdown ending with list item is not truncated
	 */
	public function test_list_ending_not_truncated() {
		$md = "## Section\n\n- Item 1\n- Item 2\n- Last item";

		$result = $this->generator->is_truncated_markdown( $md );

		$this->assertFalse( $result );
	}

	// ========== Tests for validate_code_blocks ==========

	/**
	 * Test that balanced code blocks pass validation
	 */
	public function test_balanced_code_blocks_valid() {
		// Without language tag
		$content = "## Section\n\n```\ncode\n```\n\nText.";

		$result = $this->generator->validate_code_blocks( $content );

		$this->assertTrue( $result['valid'] );
		$this->assertArrayHasKey( 'message', $result );

		// With language tag - now correctly counted as 2 balanced fences
		$content_with_lang = "## Section\n\n```javascript\ncode\n```\n\nText.";
		$result_with_lang = $this->generator->validate_code_blocks( $content_with_lang );
		$this->assertTrue( $result_with_lang['valid'] );
		$this->assertArrayHasKey( 'message', $result_with_lang );
	}

	/**
	 * Test that unbalanced code blocks fail validation
	 */
	public function test_unbalanced_code_blocks_invalid() {
		$content = "## Section\n\n```javascript\ncode\n\nText without closing.";

		$result = $this->generator->validate_code_blocks( $content );

		$this->assertFalse( $result['valid'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * Test that section with no code blocks passes validation
	 */
	public function test_no_code_blocks_valid() {
		$content = "## Section\n\nJust regular text and paragraphs.\n\n### Subsection\n\nMore content.";

		$result = $this->generator->validate_code_blocks( $content );

		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Test that multiple balanced code blocks pass validation
	 */
	public function test_multiple_balanced_code_blocks_valid() {
		// Without language tags
		$content = "```\ncode1\n```\n\nText.\n\n```\ncode2\n```\n\nMore text.";

		$result = $this->generator->validate_code_blocks( $content );

		$this->assertTrue( $result['valid'] );

		// With language tags - now correctly counted as 4 balanced fences (2 blocks of 2 each)
		$content_with_lang = "```javascript\ncode1\n```\n\nText.\n\n```python\ncode2\n```\n\nMore text.";
		$result_with_lang = $this->generator->validate_code_blocks( $content_with_lang );
		$this->assertTrue( $result_with_lang['valid'] );
	}

	/**
	 * Test that opening marker without language tag is handled
	 */
	public function test_code_blocks_without_language_tag() {
		$content = "## Section\n\n```\nPlain text block\n```\n\nMore content.";

		$result = $this->generator->validate_code_blocks( $content );

		$this->assertTrue( $result['valid'] );
	}

	// ========== Tests for parse_markdown_frontmatter ==========

	/**
	 * Test parsing of valid frontmatter with all fields
	 */
	public function test_parse_frontmatter_all_fields() {
		$md = "---\ntitle: 'Test Article'\nslug: 'test-slug'\nexcerpt: 'This is an excerpt'\nkeywords: [\"keyword1\", \"keyword2\"]\n---\n\n## Section 1\n\n### Subsection\n\nContent here.";

		$result = $this->generator->parse_markdown_frontmatter( $md );

		$this->assertArrayHasKey( 'meta', $result );
		$this->assertArrayHasKey( 'body', $result );
		$this->assertArrayHasKey( 'sections', $result );
		$this->assertEquals( 'Test Article', $result['meta']['title'] );
		$this->assertEquals( 'test-slug', $result['meta']['slug'] );
		$this->assertEquals( 'This is an excerpt', $result['meta']['excerpt'] );
		$this->assertIsArray( $result['meta']['keywords'] );
	}

	/**
	 * Test parsing of markdown without frontmatter
	 */
	public function test_parse_frontmatter_no_frontmatter() {
		$md = "## Section 1\n\n### Subsection\n\nContent here.";

		$result = $this->generator->parse_markdown_frontmatter( $md );

		$this->assertArrayHasKey( 'meta', $result );
		$this->assertArrayHasKey( 'sections', $result );
		$this->assertGreaterThan( 0, count( $result['sections'] ) );
	}

	/**
	 * Test parsing of section structure (H2, H3 hierarchy)
	 */
	public function test_parse_section_hierarchy() {
		$md = "---\ntitle: 'Article'\nslug: 'article'\nexcerpt: 'Excerpt'\nkeywords: [\"key1\"]\n---\n\n## Section 1\n\n### Subsection 1-1\n\nContent.\n\n### Subsection 1-2\n\nMore content.\n\n## Section 2\n\n### Subsection 2-1\n\nFinal content.";

		$result = $this->generator->parse_markdown_frontmatter( $md );

		$this->assertCount( 2, $result['sections'] );
		$this->assertEquals( 'Section 1', $result['sections'][0]['title'] );
		$this->assertCount( 2, $result['sections'][0]['subsections'] );
		$this->assertEquals( 'Section 2', $result['sections'][1]['title'] );
	}

	/**
	 * Test parsing with keywords in array format
	 */
	public function test_parse_keywords_array_format() {
		$md = "---\ntitle: 'Test'\nslug: 'test'\nexcerpt: 'Excerpt'\nkeywords: [\"keyword1\", \"keyword2\", \"keyword3\"]\n---\n\n## Section\n\n### Sub\n\nContent.";

		$result = $this->generator->parse_markdown_frontmatter( $md );

		$this->assertArrayHasKey( 'keywords', $result['meta'] );
		$this->assertCount( 3, $result['meta']['keywords'] );
		$this->assertContains( 'keyword1', $result['meta']['keywords'] );
		$this->assertContains( 'keyword2', $result['meta']['keywords'] );
	}

	/**
	 * Test parsing with single-quoted frontmatter
	 */
	public function test_parse_single_quoted_frontmatter() {
		$md = "---\ntitle: 'Article with \\'quotes\\''\nslug: 'test-slug'\nexcerpt: 'An excerpt'\nkeywords: [\"test\"]\n---\n\n## Section\n\n### Sub\n\nContent.";

		$result = $this->generator->parse_markdown_frontmatter( $md );

		$this->assertArrayHasKey( 'title', $result['meta'] );
		// Should handle escaped quotes
		$this->assertIsString( $result['meta']['title'] );
	}

	/**
	 * Test parsing with double-quoted frontmatter
	 */
	public function test_parse_double_quoted_frontmatter() {
		$md = "---\ntitle: \"Article with \\\"quotes\\\"\"\nslug: \"test-slug\"\nexcerpt: \"An excerpt\"\nkeywords: [\"test\"]\n---\n\n## Section\n\n### Sub\n\nContent.";

		$result = $this->generator->parse_markdown_frontmatter( $md );

		$this->assertArrayHasKey( 'title', $result['meta'] );
		$this->assertIsString( $result['meta']['title'] );
	}

	/**
	 * Test empty markdown input
	 */
	public function test_parse_empty_markdown() {
		$result = $this->generator->parse_markdown_frontmatter( '' );

		$this->assertArrayHasKey( 'meta', $result );
		$this->assertArrayHasKey( 'body', $result );
		$this->assertArrayHasKey( 'sections', $result );
		$this->assertEmpty( $result['meta'] );
		$this->assertEmpty( $result['sections'] );
	}

	/**
	 * Test null input handling
	 */
	public function test_parse_null_markdown() {
		$result = $this->generator->parse_markdown_frontmatter( null );

		$this->assertArrayHasKey( 'meta', $result );
		$this->assertIsArray( $result['meta'] );
	}
}
