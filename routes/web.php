    <?php
    use App\Http\Controllers\ProfileController;
    use App\Http\Controllers\DashboardController;
    use App\Http\Controllers\ChatController;
    use App\Http\Controllers\RoomSkillController;
    use App\Http\Controllers\BrainController;
    use App\Http\Controllers\GoogleController;
    use Illuminate\Support\Facades\Route;

    /*
    |--------------------------------------------------------------------------
    | Web Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/', function () {
        return view('welcome');
    });

    // Dashboard utama dengan Analytics (Charts & Statistics)
    Route::middleware(['auth', 'verified'])->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/dashboard/provider-mode', [DashboardController::class, 'updateProviderMode'])->name('dashboard.updateProviderMode');
        Route::get('/dashboard/graph-data', [DashboardController::class, 'getGraphData'])->name('dashboard.graphData');
        Route::get('/dashboard/graph-rooms', [DashboardController::class, 'getGraphRooms'])->name('dashboard.graphRooms');
        Route::post('/dashboard/analyze-user', [DashboardController::class, 'analyzeUser'])->name('dashboard.analyzeUser');
        Route::get('/dashboard/analysis/{analysis}', [DashboardController::class, 'getAnalysisStatus'])->name('dashboard.analysisStatus');
        Route::post('/dashboard/analysis/{analysis}/cancel', [DashboardController::class, 'cancelAnalysis'])->name('dashboard.cancelAnalysis');

        // Kelompok Chat
        Route::prefix('chat')->group(function () {
            Route::get('/', [ChatController::class, 'index'])->name('chat.index');
            Route::get('/create', [ChatController::class, 'create'])->name('chat.create');
            Route::post('/create', [ChatController::class, 'store'])->name('chat.store');

            // ---> TAMBAHKAN UNTUK RATING, DELETE, & EXPORT PESAN <---
            Route::post('/message/{message}/rate', [ChatController::class, 'rateMessage'])->name('chat.message.rate');
            Route::delete('/message/{message}', [ChatController::class, 'destroyMessage'])->name('chat.message.destroy');
            Route::get('/message/{message}/export-docx', [ChatController::class, 'exportDocx'])->name('chat.message.exportDocx');
            
            // Route for file upload & stop processing
            Route::post('/stop-processing', [ChatController::class, 'stopProcessing'])->name('chat.stopProcessing');
            Route::post('/upload-file', [ChatController::class, 'uploadFile'])->name('chat.uploadFile');

            // Route yang sudah ada sebelumnya
            Route::get('/{room}', [ChatController::class, 'show'])->name('chat.show');
            Route::post('/{room}/send', [ChatController::class, 'send'])->name('chat.send');
            Route::post('/{room}/retry/{message}', [ChatController::class, 'retry'])->name('chat.retry');
            Route::delete('/{room}', [ChatController::class, 'destroy'])->name('chat.destroy');

           Route::post('/room/{room}/context', [ChatController::class, 'updateContext'])->name('chat.updateContext');

            // Room Skills
            Route::get('/{room}/skills', [RoomSkillController::class, 'index'])->name('chat.skills.index');
            Route::post('/{room}/skills', [RoomSkillController::class, 'store'])->name('chat.skills.store');
            Route::patch('/{room}/skills/{skill}/toggle', [RoomSkillController::class, 'toggle'])->name('chat.skills.toggle');
            Route::delete('/{room}/skills/{skill}', [RoomSkillController::class, 'destroy'])->name('chat.skills.destroy');
        });

        // Google Workspace Integration Routes
        Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->name('google.redirect');
        Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
        Route::get('/auth/google/status', [GoogleController::class, 'status'])->name('google.status');
        Route::post('/auth/google/disconnect', [GoogleController::class, 'disconnect'])->name('google.disconnect');
        Route::post('/chat/message/{message}/export-google-docs', [GoogleController::class, 'exportDocs'])->name('chat.message.exportGoogleDocs');
        Route::post('/chat/message/{message}/export-google-sheets', [GoogleController::class, 'exportSheets'])->name('chat.message.exportGoogleSheets');

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
        
        // Brain (Knowledge Base) Routes
        Route::resource('brains', BrainController::class)->except(['show']);
    });

    require __DIR__.'/auth.php';