<?php

namespace Biigle\BIIGLEBot\BIIGLEBot\Http\Controllers;

use Biigle\Http\Controllers\Views\Controller;

class QuotesController extends Controller
{

    /**
     * Shows the quotes page.
     *
     * @return mixed
     */
    public function index()
    {
        return view('BIIGLEBot::index');
    }

    /**
     * Returns a new inspiring quote.
     *
     * @return \Illuminate\Http\Response
     */
    public function quote()
    {
        return \Illuminate\Foundation\Inspiring::quote();
    }
}
