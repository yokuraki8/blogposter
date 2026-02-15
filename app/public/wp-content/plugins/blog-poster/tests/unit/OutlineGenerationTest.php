<?php
/**
 * PHPUnit tests for Blog Poster outline generation functionality
 *
 * Tests the Blog_Poster_Generator class, focusing on:
 * - Markdown frontmatter parsing
 * - Section and subsection extraction
 * - Article length configuration
 * - Compatibility with various AI model output formats (Gemini, Claude, OpenAI)
 *
 * @package Blog_Poster
 * @subpackage Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * Test class for outline generation in Blog Poster plugin
 */
class OutlineGenerationTest extends TestCase {

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
		$this->generator = new Blog_Poster_Generator();
	}

	/**
	 * Test parsing valid standard outline with 4 sections
	 *
	 * @return void
	 */
	public function testParseValidStandardOutline() {
		$md = "---\ntitle: \"テスト記事タイトル\"\nslug: \"test-article\"\nexcerpt: \"テスト抜粋文です。\"\nkeywords: [\"テスト\", \"PHP\"]\n---\n\n## セクション1\n\n### サブ1-1\n\n### サブ1-2\n\n## セクション2\n\n### サブ2-1\n\n## セクション3\n\n### サブ3-1\n\n### サブ3-2\n\n## セクション4\n\n### サブ4-1\n";

		$result = $this->generator->parse_markdown_frontmatter( $md );

		$this->assertArrayHasKey( 'meta', $result );
		$this->assertArrayHasKey( 'sections', $result );
		$this->assertEquals( 'テスト記事タイトル', $result['meta']['title'] );
		$this->assertEquals( 'test-article', $result['meta']['slug'] );
		$this->assertCount( 4, $result['sections'] );
		$this->assertEquals( 'セクション1', $result['sections'][0]['title'] );
	}

	/**
	 * Test parsing short outline with minimum 3 sections
	 *
	 * @return void
	 */
	public function testParseShortOutlineMinSections() {
		$md = "---\ntitle: \"短い記事\"\nslug: \"short\"\nexcerpt: \"短い抜粋\"\nkeywords: [\"短い\"]\n---\n\n## 第1章\n\n### 内容1\n\n## 第2章\n\n### 内容2\n\n## 第3章\n\n### 内容3\n";

		$result = $this->generator->parse_markdown_frontmatter( $md );
		$this->assertCount( 3, $result['sections'] );
	}

	/**
	 * Test parsing long outline with maximum 8 sections
	 *
	 * @return void
	 */
	public function testParseLongOutlineMaxSections() {
		$sections = '';
		for ( $i = 1; $i <= 8; $i++ ) {
			$sections .= "\n## セクション{$i}\n\n### サブ{$i}-1\n\n### サブ{$i}-2\n";
		}
		$md = "---\ntitle: \"長い記事\"\nslug: \"long\"\nexcerpt: \"長い抜粋\"\nkeywords: [\"長い\"]\n---\n" . $sections;

		$result = $this->generator->parse_markdown_frontmatter( $md );
		$this->assertCount( 8, $result['sections'] );
	}

	/**
	 * Test parsing frontmatter with Japanese characters in all fields
	 *
	 * @return void
	 */
	public function testParseFrontmatterJapaneseCharacters() {
		$md = "---\ntitle: \"日本語テスト：特殊文字「」を含む\"\nslug: \"japanese-test\"\nexcerpt: \"日本語の抜粋文。句読点、括弧（）も含む。\"\nkeywords: [\"日本語\", \"テスト\", \"特殊文字\"]\n---\n\n## 日本語セクション1\n\n### サブセクション\n\n## 日本語セクション2\n\n### サブセクション\n\n## 日本語セクション3\n\n### サブセクション\n\n## 日本語セクション4\n\n### サブセクション\n";

		$result = $this->generator->parse_markdown_frontmatter( $md );
		$this->assertStringContainsString( '日本語テスト', $result['meta']['title'] );
		$this->assertCount( 3, $result['meta']['keywords'] );
	}

	/**
	 * Test parsing with missing frontmatter fields
	 *
	 * @return void
	 */
	public function testParseMissingFrontmatterFields() {
		$md = "---\ntitle: \"タイトルのみ\"\n---\n\n## セクション1\n\n### サブ1\n\n## セクション2\n\n### サブ2\n\n## セクション3\n\n### サブ3\n\n## セクション4\n\n### サブ4\n";

		$result = $this->generator->parse_markdown_frontmatter( $md );
		$this->assertArrayHasKey( 'meta', $result );
		// Should still parse sections even with incomplete frontmatter
		$this->assertGreaterThanOrEqual( 4, count( $result['sections'] ) );
	}

	/**
	 * Test parsing with no frontmatter at all
	 *
	 * @return void
	 */
	public function testParseNoFrontmatter() {
		$md = "## セクション1\n\n### サブ1\n\n## セクション2\n\n### サブ2\n\n## セクション3\n\n### サブ3\n";

		$result = $this->generator->parse_markdown_frontmatter( $md );
		// Should still extract sections even without frontmatter
		$this->assertArrayHasKey( 'sections', $result );
		$this->assertGreaterThanOrEqual( 3, count( $result['sections'] ) );
	}

	/**
	 * Test set_article_length accepts valid values without error
	 *
	 * @return void
	 */
	public function testSetArticleLengthValid() {
		foreach ( [ 'short', 'standard', 'long' ] as $length ) {
			$this->generator->set_article_length( $length );
			// No exception = pass
			$this->assertTrue( true );
		}
	}

	/**
	 * Test subsections are properly parsed under each H2 section
	 *
	 * @return void
	 */
	public function testSubsectionsProperlyParsed() {
		$md = "---\ntitle: \"テスト\"\nslug: \"test\"\nexcerpt: \"抜粋\"\nkeywords: [\"テスト\"]\n---\n\n## メインセクション\n\n### サブA\n\nサブAの内容\n\n### サブB\n\nサブBの内容\n\n### サブC\n\n## 次のセクション\n\n### サブD\n\n## 第3セクション\n\n### サブE\n\n## 第4セクション\n\n### サブF\n";

		$result = $this->generator->parse_markdown_frontmatter( $md );
		$firstSection = $result['sections'][0];
		$this->assertEquals( 'メインセクション', $firstSection['title'] );
		$this->assertNotEmpty( $firstSection['subsections'] );
		// First section should have 3 subsections (サブA, サブB, サブC)
		$this->assertCount( 3, $firstSection['subsections'] );
	}

	/**
	 * Test Gemini-style outline parsing with extra whitespace and formatting
	 *
	 * Gemini sometimes adds extra blank lines or slightly different formatting.
	 * This test verifies the parser can handle such variations.
	 *
	 * @return void
	 */
	public function testGeminiStyleOutlineParsing() {
		$md = "---\ntitle: \"Geminiスタイルテスト\"\nslug: \"gemini-test\"\nexcerpt: \"Geminiが生成する形式\"\nkeywords: [\"Gemini\", \"テスト\"]\n---\n\n\n## セクション 1: 導入\n\n\n### 背景と現状\n\n\n### 目的\n\n\n## セクション 2: 本論\n\n\n### 詳細分析\n\n\n### 実践例\n\n\n## セクション 3: 応用\n\n\n### ケーススタディ\n\n\n## セクション 4: まとめ\n\n\n### 結論\n\n\n### 今後の展望\n\n";

		$result = $this->generator->parse_markdown_frontmatter( $md );
		$this->assertCount( 4, $result['sections'] );
	}

	/**
	 * Test Claude-style outline parsing with clean formatting
	 *
	 * Claude typically produces cleaner, more structured formatting.
	 * This test verifies compatibility with Claude's output style.
	 *
	 * @return void
	 */
	public function testClaudeStyleOutlineParsing() {
		$md = "---\ntitle: \"Claudeスタイルテスト\"\nslug: \"claude-test\"\nexcerpt: \"Claudeが生成する形式のテスト\"\nmeta_description: \"Claudeモデルのアウトライン形式検証\"\nkeywords: [\"Claude\", \"テスト\", \"AI\"]\n---\n\n## はじめに：基本概念の理解\n\n### 定義と背景\n\n### 重要性\n\n## 実践的アプローチ\n\n### ステップバイステップガイド\n\n### ベストプラクティス\n\n## 応用と発展\n\n### 高度なテクニック\n\n### 注意点とトラブルシューティング\n\n## まとめと次のステップ\n\n### 振り返り\n\n### 推奨リソース\n";

		$result = $this->generator->parse_markdown_frontmatter( $md );
		$this->assertCount( 4, $result['sections'] );
		$this->assertEquals( 'Claudeスタイルテスト', $result['meta']['title'] );
	}

	/**
	 * Test OpenAI-style outline parsing with numbered sections
	 *
	 * OpenAI models may include numbered sections in the outline.
	 * This test ensures the parser handles such formatting correctly.
	 *
	 * @return void
	 */
	public function testOpenAIStyleOutlineParsing() {
		$md = "---\ntitle: \"OpenAIスタイルテスト\"\nslug: \"openai-test\"\nexcerpt: \"OpenAIが生成する形式のテスト\"\nkeywords: [\"OpenAI\", \"GPT\"]\n---\n\n## 1. 概要\n\n### 1.1 背景\n\n### 1.2 目的\n\n## 2. 詳細分析\n\n### 2.1 データ収集\n\n### 2.2 分析手法\n\n## 3. 結果と考察\n\n### 3.1 主要な発見\n\n### 3.2 示唆\n\n## 4. 結論\n\n### 4.1 まとめ\n\n### 4.2 今後の課題\n";

		$result = $this->generator->parse_markdown_frontmatter( $md );
		$this->assertCount( 4, $result['sections'] );
	}

	/**
	 * Test that meta array contains all expected keys when present
	 *
	 * @return void
	 */
	public function testMetaArrayStructure() {
		$md = "---\ntitle: \"テスト記事\"\nslug: \"test-slug\"\nexcerpt: \"テスト抜粋\"\nkeywords: [\"キーワード1\", \"キーワード2\"]\n---\n\n## セクション1\n\n### サブ1\n\n## セクション2\n\n### サブ2\n\n## セクション3\n\n### サブ3\n\n## セクション4\n\n### サブ4\n";

		$result = $this->generator->parse_markdown_frontmatter( $md );
		$meta = $result['meta'];

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'title', $meta );
		$this->assertArrayHasKey( 'slug', $meta );
		$this->assertArrayHasKey( 'excerpt', $meta );
		$this->assertArrayHasKey( 'keywords', $meta );
	}

	/**
	 * Test that sections array contains expected structure
	 *
	 * @return void
	 */
	public function testSectionsArrayStructure() {
		$md = "---\ntitle: \"テスト\"\nslug: \"test\"\nexcerpt: \"抜粋\"\nkeywords: [\"キーワード\"]\n---\n\n## メインセクション\n\n### サブセクション1\n\n### サブセクション2\n\n## 次のセクション\n\n### サブセクション3\n\n## 第3セクション\n\n### サブセクション4\n\n## 第4セクション\n\n### サブセクション5\n";

		$result = $this->generator->parse_markdown_frontmatter( $md );
		$sections = $result['sections'];

		$this->assertIsArray( $sections );
		$this->assertNotEmpty( $sections );

		foreach ( $sections as $section ) {
			$this->assertArrayHasKey( 'title', $section );
			$this->assertArrayHasKey( 'subsections', $section );
			$this->assertIsString( $section['title'] );
			$this->assertIsArray( $section['subsections'] );
		}
	}

	/**
	 * Test empty markdown returns valid structure
	 *
	 * @return void
	 */
	public function testEmptyMarkdownHandling() {
		$result = $this->generator->parse_markdown_frontmatter( '' );

		$this->assertArrayHasKey( 'meta', $result );
		$this->assertArrayHasKey( 'sections', $result );
	}

	/**
	 * Test special characters in section titles are preserved
	 *
	 * @return void
	 */
	public function testSpecialCharactersPreserved() {
		$md = "---\ntitle: \"テスト & 記事 | 特殊\"\nslug: \"test\"\nexcerpt: \"抜粋\"\nkeywords: [\"テスト\"]\n---\n\n## セクション & 特殊\n\n### サブ | セクション\n\n## 第2セクション\n\n### サブ2\n\n## 第3セクション\n\n### サブ3\n\n## 第4セクション\n\n### サブ4\n";

		$result = $this->generator->parse_markdown_frontmatter( $md );

		$this->assertStringContainsString( '&', $result['meta']['title'] );
		$this->assertStringContainsString( '&', $result['sections'][0]['title'] );
	}

	/**
	 * Test keywords are properly parsed as array
	 *
	 * @return void
	 */
	public function testKeywordsArrayParsing() {
		$md = "---\ntitle: \"テスト\"\nslug: \"test\"\nexcerpt: \"抜粋\"\nkeywords: [\"キーワード1\", \"キーワード2\", \"キーワード3\"]\n---\n\n## セクション1\n\n### サブ1\n\n## セクション2\n\n### サブ2\n\n## セクション3\n\n### サブ3\n\n## セクション4\n\n### サブ4\n";

		$result = $this->generator->parse_markdown_frontmatter( $md );
		$keywords = $result['meta']['keywords'];

		$this->assertIsArray( $keywords );
		$this->assertCount( 3, $keywords );
		$this->assertContains( 'キーワード1', $keywords );
		$this->assertContains( 'キーワード2', $keywords );
		$this->assertContains( 'キーワード3', $keywords );
	}
}
