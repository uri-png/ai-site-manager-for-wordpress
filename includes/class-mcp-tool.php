<?php
/**
 * Tool definition: the unit the registry manages, the executor runs and the
 * MCP endpoint describes to clients.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single executable WordPress action exposed to AI agents.
 */
final class MCP_Tool {

	/**
	 * Executes the tool with validated args; returns data (array/scalar) or throws.
	 *
	 * @var Closure
	 */
	public Closure $callback;

	/**
	 * Returns true when the current user may run the tool with these args.
	 *
	 * @var Closure
	 */
	public Closure $cap_check;

	/**
	 * Optional lighter schema used by the executor for validation (the MCP
	 * listing still shows input_schema). Null means validate against input_schema.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $exec_schema = null;

	/**
	 * Set only by MCP_Registry for this plugin's own built-in read tools.
	 * Annotations and tool names supplied by other code are never trusted.
	 *
	 * @var bool
	 */
	public bool $verified_read = false;

	/**
	 * @param string                $name          Machine name: [a-z0-9_], max 64 chars (MCP-safe).
	 * @param string                $title         Human title.
	 * @param string                $description   What the tool does; shown to the AI agent.
	 * @param string                $category      Grouping for the admin UI (posts, pages, media, users, site, woocommerce, cpt:<type>).
	 * @param array<string, mixed>  $input_schema  JSON-Schema object describing accepted arguments.
	 * @param callable(array):mixed $callback      Executes with validated args; returns data or throws/WP_Error.
	 * @param callable(array):bool  $cap_check     Capability policy for the current user and these args.
	 * @param bool                  $destructive   Destructive/high-risk tools default OFF and can require approval.
	 * @param array<string, mixed>  $annotations   Extra MCP annotations (merged over defaults).
	 */
	public function __construct(
		public string $name,
		public string $title,
		public string $description,
		public string $category,
		public array $input_schema,
		callable $callback,
		callable $cap_check,
		public bool $destructive = false,
		public array $annotations = array()
	) {
		$this->callback  = $callback instanceof Closure ? $callback : Closure::fromCallable( $callback );
		$this->cap_check = $cap_check instanceof Closure ? $cap_check : Closure::fromCallable( $cap_check );
	}

	/**
	 * Whether this tool is a verified read-only tool. Fails closed: only this
	 * plugin's own built-in read tools qualify. A tool's own name or MCP
	 * annotations (including bridged third-party abilities and anything added
	 * through the wpmcp_tools filter) do not make it read-only for the
	 * access mode.
	 */
	public function is_read_only(): bool {
		return ! $this->destructive && $this->verified_read;
	}

	/**
	 * MCP tools/list representation.
	 *
	 * @return array<string,mixed>
	 */
	public function to_mcp_schema(): array {
		$read_only = ! $this->destructive
			&& ( $this->verified_read || ( $this->annotations['readOnlyHint'] ?? false ) === true
				|| str_ends_with( $this->name, '_list' ) || str_ends_with( $this->name, '_get' ) || 'site_info' === $this->name );

		return array(
			'name'        => $this->name,
			'title'       => $this->title,
			'description' => $this->description,
			'inputSchema' => $this->input_schema,
			'annotations' => array_merge(
				array(
					'title'           => $this->title,
					'readOnlyHint'    => $read_only,
					'destructiveHint' => $this->destructive,
					'openWorldHint'   => false,
				),
				$this->annotations
			),
		);
	}
}


