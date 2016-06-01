<?php namespace AccResults\Http\Controllers;

use AccResults\Http\Requests;
use AccResults\Http\Controllers\Controller;

use Illuminate\Http\Request;
use AccResults\Http\Requests\TeamRequest;
use Illuminate\Support\Facades\Input;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon;

use AccResults\Team;
use AccResults\User;
use AccResults\UserProfile;
use AccResults\Season;

use Illuminate\Support\Facades\DB;

class TeamsController extends Controller {
    
    public function __construct()
    {
        $this->middleware('auth');
	$this->middleware('admin_check');
        $this->middleware('ajax', ['only' => [
                'showCaptainForm',
                //'showProfilesAutocomplete',
            ]]);
    }
    
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index() {
        $teamsQuery = Team::select(['teams.*', DB::raw('count(user_profiles.profile_id) as profiles')]);
        
        $teamsQuery->join('user_profiles', 'teams.team_id', '=', 'user_profiles.team_id', 'LEFT');
        $teamsQuery->groupBy('teams.team_id');
        
        if (Input::has('name')) {
            $teamsQuery->where('team_name', 'LIKE', '%'.Input::get('name').'%');
        }
        
        if (Input::has('sort')) {
            switch (Input::get('sort')) {
                case 'name':
                    $sort_field = 'teams.team_name'; break;
                case 'captain':
                    $sort_field = 'teams.captain_user_id'; break;
                case 'members':
                    $sort_field = 'profiles'; break;
                default:
                    $sort_field = 'team_id'; break;
            }
            if (Input::has('type') && Input::get('type') == 'desc') $type = 'DESC';
                else $type = 'ASC';
            $teamsQuery->orderBy($sort_field, $type);
        }
        
        $page = Paginator::resolveCurrentPage();
        $count = count($teamsQuery->get());
        $team_models = $teamsQuery->take(10)->offset(10*($page-1))->get();
        
        $years = Season::lists('year', 'year');
        $teams = new LengthAwarePaginator($team_models, $count, 10, $page, [
                                'path' => Paginator::resolveCurrentPath(),
                        ]);

        // dd($teams->first()->captain);
        return view('teams.index', compact('teams', 'years'));
    }
    
    /**
     * Display the specified resource.
     *
     * @param  int  $team_id
     * @return Response
     */
    public function show($team_id)
    {

        $team = Team::where('team_id', $team_id)->firstOrFail();
        
	return view('teams.single', compact('team'));
    }
    
    public function create() {
        $seasonModels = Season::all();
        $seasons = [];
        foreach($seasonModels as $season) {
            $seasons[$season->season_id] = $season->year.' '. $season->discipline->discipline_title;
        }
        
        return view('teams.create', compact('seasons'));
    }
    
    public function store(TeamRequest $request) {
        $data = $request->all();
        $data['year'] = Carbon::now()->format('Y');
        $team = Team::create($data);
        
        if (!$team) {
            flash()->error(trans('teams.create.error'));
            return redirect()->action('TeamsController@create');
        }
        
        flash()->success(trans('teams.create.success'));
        return redirect()->action('TeamsController@show', ['team_id'=>$team->team_id]);
    }
    
    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $team_id
     * @return Response
     */
    public function edit($team_id)
    {
            $team = Team::where('team_id', $team_id)->firstOrFail();
            return view('teams.edit', compact('team'));
    }
    
    public function update(TeamRequest $request, $team_id) {
        $team = Team::where('team_id', $team_id)->firstOrFail();
        $team->update($request->all());
        
        flash()->success(trans('teams.update.success'));
        return redirect()->action('TeamsController@show', ['team_id'=>$team->team_id]);
    }


    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($team_id) {
        $team = Team::where('team_id', $team_id)->firstOrFail();
        $team->delete();
        
        flash()->success(trans('teams.delete.success'));
        return redirect()->action('TeamsController@index');
    }
    
    public function showCaptainForm($team_id) {
        $team = Team::find($team_id);
        if (!$team) {
            return json_encode(['status'=>false]);
        }
        
        $content = view('partials.teams._captain-view-form', compact('team'))->render();
        $row = ['content'=>$content, 'status'=>true];
        return json_encode($row);
    }
    
    public function updateCaptain($team_id, Request $request) {
        $team = Team::where('team_id', $team_id)->firstOrFail();
        
        $user_id = explode(',', $request->get('captain_user_id'));
        
        $user = User::where('id', $user_id)->firstOrFail();
        $exist_teams = Team::where('captain_user_id', $user->id)->count();
        if ($exist_teams == 0) {
            $team->captain_user_id = $user->id;
            $team->save();
            flash()->success(trans('teams.captain.change.success'));
        } else {
            flash()->error(trans('teams.captain.change.error'));
        }
        return redirect()->action('TeamsController@show', ['team_id'=>$team->team_id]);
    }
    
    public function addMember($team_id, Request $request) {
        $team = Team::where('team_id', $team_id)->firstOrFail();
        $profile = UserProfile::where('profile_id', $request->get('profile'))->firstOrFail();
        $profile->team_id = $team->team_id;
        $profile->save();
        
        flash()->success(trans('teams.member.add.success'));
        return redirect()->action('TeamsController@show', ['team_id'=>$team->team_id]);
    }

    public function removeMember($team_id, $profile_id) {
        $team = Team::where('team_id', $team_id)->firstOrFail();
        $profile = UserProfile::where('profile_id', $profile_id)->where('team_id', $team->team_id)->firstOrFail();
        $profile->team_id = null;
        $profile->save();
        
        flash()->success(trans('teams.member.delete.success'));
        return redirect()->action('TeamsController@show', ['team_id'=>$team->team_id]);
    }

    public function showProfilesAutocomplete() {        
        $search = Input::get('query');
        $users = User::where(function ($query) use ($search) {
            $query->where('first_name', 'LIKE', '%'.$search.'%')
                    ->orWhere('last_name', 'LIKE', '%'.$search.'%')
                    ->orWhere('email', 'LIKE', '%'.$search.'%');
        })->take(5)->get();
        $suggestions = [];
        foreach($users as $user) {
            $suggestions[] = array('value'=>$user->first_name . ' ' . $user->last_name . ', '.$user->email, 'data'=>$user->id);
        }
        $row = ['query'=>$search, 'suggestions'=>$suggestions, 'status'=>true];
        return json_encode($row);
    }
    
    public function showMemberForm($team_id) {
        $team = Team::find($team_id);
        if (!$team) {
            return json_encode(['status'=>false]);
        }
        
        $content = view('partials.teams._member-add-form', compact('team'))->render();
        $row = ['content'=>$content, 'status'=>true];
        return json_encode($row);
    }
    
}
