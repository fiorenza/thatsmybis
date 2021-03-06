<?php

namespace App\Http\Controllers;

use App\{AuditLog, Guild, Member, Permission, Role, User};
use Auth;
use Exception;
use Illuminate\Http\Request;
use RestCord\DiscordClient;

class GuildController extends Controller
{
    const ADMIN_PERMISSIONS = 0x8;
    const MANAGEMENT_PERMISSIONS = 0x20;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware(['auth', 'seeUser']);
    }

    /**
     * Default page for landing on a guild
     *
     * @return \Illuminate\Http\Response
     */
    public function home($guildSlug)
    {
        $guild         = request()->get('guild');
        $currentMember = request()->get('currentMember');

        return redirect()->route('member.show', ['guildSlug' => $guild->slug, 'username' => $currentMember->username]);
    }

    /**
     * Show the guild registration.
     *
     * @return \Illuminate\Http\Response
     */
    public function showRegister()
    {
        $user = request()->get('currentUser');

        $guildArray = [];

        // Fetch guilds the user can join that already exist on this website
        if ($user->discord_token) {
            $discord = new DiscordClient([
                'token' => $user->discord_token,
                'tokenType' => 'OAuth',
            ]);

            $guilds = $discord->user->getCurrentUserGuilds();

            if ($guilds) {
                foreach ($guilds as $guild) {
                    // only add guilds they have admin permissions for
                    if (($guild->permissions & self::ADMIN_PERMISSIONS) == self::ADMIN_PERMISSIONS) {
                        $guildArray[$guild->id] = [
                            'id'          => $guild->id,
                            'name'        => $guild->name,
                            'registered'  => false,
                            'permissions' => $guild->permissions,
                        ];
                    }
                }

                $existingGuilds = Guild::whereIn('discord_id', array_keys($guildArray))->get();

                // Flag guilds that are already registered
                foreach ($existingGuilds as $guild) {
                    if (isset($guildArray[$guild->discord_id])) {
                        $guildArray[$guild->discord_id]['registered'] = true;
                    }
                }
            }
        }

        return view('guild.register', ['guilds' => $guildArray]);
    }

    /**
     * Register a guild
     *
     * @return \Illuminate\Http\Response
     */
    public function register()
    {
        $validationRules =  [
            'name'              => 'string|max:36|unique:guilds,name',
            'discord_id_select' => 'nullable|string|max:255|unique:guilds,discord_id|required_without:discord_id',
            'discord_id'        => 'nullable|string|max:255|unique:guilds,discord_id|required_without:discord_id_select',
            'bot_added'         => 'numeric|gte:1',
        ];

        $validationMessages = [
        ];

        $this->validate(request(), $validationRules);

        $input = request()->all();
        $user = Auth::user();

        $discordId = null;

        if ($input['discord_id']) {
            $discordId = $input['discord_id'];
        } else if ($input['discord_id_select']) {
            $discordId = $input['discord_id_select'];
        }

        // Verify that the bot is on the server
        $discord = new DiscordClient(['token' => env('DISCORD_BOT_TOKEN')]);

        try {
            $discordMember = $discord->guild->getGuildMember(['guild.id' => (int)$discordId, 'user.id' => (int)$user->discord_id]);
        } catch (Exception $e) {
            $error = \Illuminate\Validation\ValidationException::withMessages([
               'permissions' => ["Unable to find you on that server, or the bot is missing. Make sure you have the correct Discord Server ID and the bot has been added."],
            ]);
            throw $error;
        }

        $discordGuild = $discord->guild->getGuild(['guild.id' => (int)$discordId]);

        $hasPermissions = false;

        $roles = $discord->guild->getGuildRoles(['guild.id' => (int)$discordId]);


        if ($discordMember->user->id == $discordGuild->owner_id) {
            // You own the server... come right in.
            $hasPermissions = true;
        } else {
            // Go through each of the user's roles, and check to see if any of them have admin or management permissions
            // We're only going to let the user register this server if they have one of those permissions
            foreach ($discordMember->roles as $role) {
                $discordPermissions = $roles[array_search($role, array_column($roles, 'id'))]->permissions;
                if (($discordPermissions & self::ADMIN_PERMISSIONS) == self::ADMIN_PERMISSIONS) { // if we want to allow management permissions: || ($permissions & self::MANAGEMENT_PERMISSIONS) == self::MANAGEMENT_PERMISSIONS
                    $hasPermissions = true;
                    break;
                }
            }
        }

        if (!$hasPermissions) {
            $error = \Illuminate\Validation\ValidationException::withMessages([
               'permissions' => ["We couldn't find admin permissions on your account for that server. Have someone with admin permissions register your guild."],
            ]);
            throw $error;
        }

        // Create the guild
        $guild = Guild::firstOrCreate(['name' => $input['name']],
            [
                'slug'       => slug($input['name']),
                'user_id'    => $user->id,
                'discord_id' => $discordId,
            ]);

        // Insert the roles associated with this Discord
        foreach ($roles as $role) {
            $role = Role::firstOrCreate(['discord_id' => $role->id],
                [
                    'name'                => $role->name,
                    'guild_id'            => $guild->id,
                    'slug'                => slug($role->name),
                    'description'         => null,
                    'color'               => $role->color ? $role->color : null,
                    'position'            => $role->position,
                    'discord_permissions' => $role->permissions,
                ]);
        }

        $member = Member::create($user, $discordMember, $guild);

        AuditLog::create([
            'description'     => $member->username . ' registered the guild',
            'member_id'       => $member->id,
            'guild_id'        => $guild->id,
        ]);

        // Redirect to guild settings page; prompting the user to finish setup
        request()->session()->flash('status', 'Successfully registered guild.');
        return redirect()->route('guild.settings', ['guildSlug' => $guild->slug]);
    }

    /**
     * Show the guild settings page.
     *
     * @return \Illuminate\Http\Response
     */
    public function settings($guildSlug)
    {
        $guild         = request()->get('guild');
        $currentMember = request()->get('currentMember');

        if (!$currentMember->hasPermission('edit.guild')) {
            request()->session()->flash('status', 'You don\'t have permissions to view that page.');
            return redirect()->route('member.show', ['guildSlug' => $guild->slug, 'username' => $currentMember->username]);
        }

        $guild->load(['roles']);

        return view('guild.settings', [
            'currentMember' => $currentMember,
            'guild'         => $guild,
            'permissions'   => Permission::all(),
        ]);
    }

    /**
     * Submit the guild settings page.
     *
     * @return \Illuminate\Http\Response
     */
    public function submitSettings($guildSlug)
    {
        $guild         = request()->get('guild');
        $currentMember = request()->get('currentMember');

        if (!$currentMember->hasPermission('edit.guild')) {
            request()->session()->flash('status', 'You don\'t have permissions to edit that guild.');
            return redirect()->route('member.show', ['guildSlug' => $guild->slug, 'username' => $currentMember->username]);
        }

        $guild->load('roles');

        $validationRules =  [
            'name'                => 'string|max:36|unique:guilds,name,' . $guild->id,
            'calendar_link'       => 'nullable|string|max:255',
            'gm_role_id'          => 'nullable|integer|exists:roles,discord_id',
            'officer_role_id'     => 'nullable|integer|exists:roles,discord_id',
            'raid_leader_role_id' => 'nullable|integer|exists:roles,discord_id',
            'member_roles.*'      => 'nullable|integer|exists:roles,discord_id',
        ];

        $this->validate(request(), $validationRules);

        $permissions = Permission::all();

        $updateValues['name']          = request()->input('name');
        $updateValues['slug']          = slug(request()->input('name'));
        $updateValues['calendar_link'] = request()->input('calendar_link');

        if (request()->input('gm_role_id')) {
            // Let's make sure that role exists...
            $role = $guild->roles->where('discord_id', request()->input('gm_role_id'))->first();
            if ($role && $role->discord_id != $guild->gm_role_id) { // Don't bother if this role is already there; this will be duplicating the effort
                // Attach the appropriate permissions to that role!
                $rolePermissions = $permissions->whereIn('role_note', ['guild_master', 'officer', 'raid_leader']);
                $role->permissions()->sync($rolePermissions->keyBy('id')->keys()->toArray());
                $updateValues['gm_role_id'] = request()->input('gm_role_id');
            }
        } else {
            $updateValues['gm_role_id'] = null;
            if ($guild->gm_role_id) {
                // Not anymore you're not!
                // Strip this role of all it's ill-gotten permissions! Walk the plank, ya scurvy dog!
                $role = $guild->roles->where('discord_id', $guild->gm_role_id)->first();
                if ($role) {
                    $role->permissions()->detach();
                }
            }
        }

        // Copy of the GM code seen above
        if (request()->input('officer_role_id')) {
            $role = $guild->roles->where('discord_id', request()->input('officer_role_id'))->first();
            if ($role && $role->discord_id != $guild->officer_role_id) {
                $rolePermissions = $permissions->whereIn('role_note', ['officer', 'raid_leader']);
                $role->permissions()->sync($rolePermissions->keyBy('id')->keys()->toArray());
                $updateValues['officer_role_id'] = request()->input('officer_role_id');
            }
        } else {
            $updateValues['officer_role_id'] = null;
            if ($guild->officer_role_id) {
                $role = $guild->roles->where('discord_id', $guild->officer_role_id)->first();
                if ($role) {
                    $role->permissions()->detach();
                }
            }
        }

        // Copy of the GM code seen above
        if (request()->input('raid_leader_role_id')) {
            $role = $guild->roles->where('discord_id', request()->input('raid_leader_role_id'))->first();
            if ($role && $role->discord_id != $guild->raid_leader_role_id) { // Don't bother if this role is already there
                $rolePermissions = $permissions->whereIn('role_note', ['raid_leader']);
                $role->permissions()->sync($rolePermissions->keyBy('id')->keys()->toArray());
                $updateValues['raid_leader_role_id'] = request()->input('raid_leader_role_id');
            }
        } else {
            $updateValues['raid_leader_role_id'] = null;
            if ($guild->raid_leader_role_id) {
                $role = $guild->roles->where('discord_id', $guild->raid_leader_role_id)->first();
                if ($role) {
                    $role->permissions()->detach();
                }
            }
        }

        $updateValues['member_role_ids'] = implode(",", array_filter(request()->input('member_roles')));

        $auditMessage = '';

        if ($updateValues['name'] != $guild->name) {
            $auditMessage .= ' (guild name changed to ' . $updateValues['name'] . ')';
        }

        if (array_key_exists('gm_role_id', $updateValues) && $updateValues['gm_role_id'] != $guild->gm_role_id) {
            $role = $guild->roles->where('discord_id', $updateValues['gm_role_id'])->first();
            $auditMessage .= ' (GM role changed to ' . ($role ? $role->name : 'none') . ')';
        }
        if (array_key_exists('officer_role_id', $updateValues) && $updateValues['officer_role_id'] != $guild->officer_role_id) {
            $role = $guild->roles->where('discord_id', $updateValues['officer_role_id'])->first();
            $auditMessage .= ' (Officer role changed to ' . ($role ? $role->name : 'none') . ')';
        }
        if (array_key_exists('raid_leader_role_id', $updateValues) && $updateValues['raid_leader_role_id'] != $guild->raid_leader_role_id) {
            $role = $guild->roles->where('discord_id', $updateValues['raid_leader_role_id'])->first();
            $auditMessage .= ' (Raid Leader role changed to ' . ($role ? $role->name : 'none') . ')';
        }

        if ($updateValues['member_role_ids'] != $guild->member_role_ids) {
            $memberRoles = $guild->roles->whereIn('discord_id', request()->input('member_roles'));
            $memberRoleMessage = '';
            foreach ($memberRoles as $memberRole) {
                $memberRoleMessage .= $memberRole->name .', ';
            }
            $auditMessage .= ' (whitelisted member roles changed to ' . ($memberRoleMessage ? trim($memberRoleMessage, ', ') : 'none') . ')';
        }

        $guild->update($updateValues);

        AuditLog::create([
            'description'     => $currentMember->username . ' modified guild settings' . $auditMessage,
            'member_id'       => $currentMember->id,
            'guild_id'        => $guild->id,
        ]);

        request()->session()->flash('status', 'Guild settings updated.');
        return redirect()->route('guild.settings', ['guildSlug' => $guild->slug]);
    }
}
