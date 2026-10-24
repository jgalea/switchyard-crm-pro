<?php

declare( strict_types=1 );

namespace Switchyard\Pro\Feature;

interface Feature {

	public function register_hooks(): void;
}
