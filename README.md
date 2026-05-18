# BIIGLEBot - An LLM using RAG to answer questions about BIIGLE

[![Test status](https://github.com/biigle/module/workflows/Tests/badge.svg)](https://github.com/biigle/module/actions?query=workflow%3ATests)

## Installation

1. Run `composer require biigle/biiglebot:dev-main --ignore-platform-req=ext-ffi`.
2. Add `Biigle\Modules\Module\ModuleServiceProvider::class` to the `providers` array in `config/app.php`. *Replace `Module` in the class namespace with the name of your module.*
3. Run `php artisan vendor:publish --tag=public` to refresh the public assets of the modules. Do this for every update of this module.