    <?php
    use App\Http\Controllers\ProfileController;
    use App\Http\Controllers\DashboardController;
    use App\Http\Controllers\ChatController;
    use App\Http\Controllers\BrainController;
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

            // ---> TAMBAHKAN 2 BARIS INI UNTUK RATING & DELETE PESAN <---
            Route::post('/message/{message}/rate', [ChatController::class, 'rateMessage'])->name('chat.message.rate');
            Route::delete('/message/{message}', [ChatController::class, 'destroyMessage'])->name('chat.message.destroy');
            
            // Route for file upload
            Route::post('/upload-file', [ChatController::class, 'uploadFile'])->name('chat.uploadFile');

            // Route yang sudah ada sebelumnya
            Route::get('/{room}', [ChatController::class, 'show'])->name('chat.show');
            Route::post('/{room}/send', [ChatController::class, 'send'])->name('chat.send');
            Route::post('/{room}/retry/{message}', [ChatController::class, 'retry'])->name('chat.retry');
            Route::delete('/{room}', [ChatController::class, 'destroy'])->name('chat.destroy');

           Route::post('/room/{room}/context', [ChatController::class, 'updateContext'])->name('chat.updateContext');
        });

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
        
        // Brain (Knowledge Base) Routes
        Route::resource('brains', BrainController::class)->except(['show']);
    });

    require __DIR__.'/auth.php';