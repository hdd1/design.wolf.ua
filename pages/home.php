<?php
use Design\View;

View::render('layout', ['title' => 'Дизайн для друку', 'body' => View::fetch('home')]);
