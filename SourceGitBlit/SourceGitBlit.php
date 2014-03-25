<?php

# Copyright (c) 2012 John Reese
# Licensed under the MIT license

if ( false === include_once( config_get( 'plugin_path' ) . 'Source/MantisSourcePlugin.class.php' ) ) {
	return;
}

require_once( config_get( 'core_path' ) . 'url_api.php' );

class SourceGitBlitPlugin extends MantisSourcePlugin {
	public function register() {
		$this->name = plugin_lang_get( 'title' );
		$this->description = plugin_lang_get( 'description' );

		$this->version = '0.1';
		$this->requires = array(
			'MantisCore' => '1.2.17',
			'Source' => '0.18',
		);

		$this->author = 'Brion Swanson';
		$this->contact = 'brion@alum.rit.edu';
		$this->url = 'https://github.com/brions/source-integration';
	}

	public $type = 'gitblit';

	public function show_type() {
		return plugin_lang_get( 'gitblit' );
	}

	public function show_changeset( $p_repo, $p_changeset ) {
		$t_ref = substr( $p_changeset->revision, 0, 8 );
		$t_branch = $p_changeset->branch;

		return "$t_branch $t_ref";
	}

	public function show_file( $p_repo, $p_changeset, $p_file ) {
		return  "$p_file->action - $p_file->filename";
	}

        private function unslash( $p_name ) {
		return str_replace( '/', '%2F', $p_name );
	}

	private function uri_base( $p_repo ) {
		$t_uri_base = rtrim($p_repo->info['gitblit_root'], '/');
		$project_name = $this->unslash( $p_repo->info['gitblit_project'] );
		$t_uri_base = $t_uri_base . '/summary/' . $project_name;

		return $t_uri_base;
	}

	public function url_repo( $p_repo, $t_changeset=null ) {
                if ( $t_changeset ) {
			return str_replace( 'summary', 'commit', $this->uri_base( $p_repo ) ) . '/' . $t_changeset->revision;
		}
		return $this->uri_base( $p_repo );
	}

	public function url_changeset( $p_repo, $p_changeset ) {
		return str_replace( 'commit', 'commitdiff', $this->url_repo( $p_repo, $p_changeset ) );
	}

	public function url_file( $p_repo, $p_changeset=null, $p_file ) {
		if ( $p_changeset ) {
			return str_replace( 'commit', 'blob', $this->url_repo( $p_repo, $p_changeset ) ) . '/' . $this->unslash($p_file->filename);
 		}
		return str_replace( 'summary', 'blob', $this->url_repo( $p_repo, $p_changeset ) ) . '/' . $this->unslash($p_file->filename);
	}

	public function url_diff( $p_repo, $p_changeset, $p_file ) {
		return str_replace( 'blob', 'blobdiff', $this->url_file( $p_repo, $p_changeset, $p_file ) );
	}

	public function update_repo_form( $p_repo ) {
		$t_gitblit_root = null;
		$t_gitblit_project = null;

		if ( isset( $p_repo->info['gitblit_root'] ) ) {
			$t_gitblit_root = $p_repo->info['gitblit_root'];
		}

		if ( isset( $p_repo->info['gitblit_project'] ) ) {
			$t_gitblit_project = $p_repo->info['gitblit_project'];
		}

		if ( isset( $p_repo->info['master_branch'] ) ) {
			$t_master_branch = $p_repo->info['master_branch'];
		} else {
			$t_master_branch = 'master';
		}
?>
<tr <?php echo helper_alternate_class() ?>>
<td class="category"><?php echo plugin_lang_get( 'gitblit_root' ) ?></td>
<td><input name="gitblit_root" maxlength="250" size="40" value="<?php echo string_attribute( $t_gitblit_root ) ?>"/></td>
</tr>
<tr <?php echo helper_alternate_class() ?>>
<td class="category"><?php echo plugin_lang_get( 'gitblit_project' ) ?></td>
<td><input name="gitblit_project" maxlength="250" size="40" value="<?php echo string_attribute( $t_gitblit_project ) ?>"/></td>
</tr>
<tr <?php echo helper_alternate_class() ?>>
<td class="category"><?php echo plugin_lang_get( 'master_branch' ) ?></td>
<td><input name="master_branch" maxlength="250" size="40" value="<?php echo string_attribute( $t_master_branch ) ?>"/></td>
</tr>
<?php
	}

	public function update_repo( $p_repo ) {
		$f_gitblit_root = gpc_get_string( 'gitblit_root' );
		$f_gitblit_project = gpc_get_string( 'gitblit_project' );
		$f_master_branch = gpc_get_string( 'master_branch' );

		$p_repo->info['gitblit_root'] = $f_gitblit_root;
		$p_repo->info['gitblit_project'] = $f_gitblit_project;
		$p_repo->info['master_branch'] = $f_master_branch;

		return $p_repo;
	}

	public function precommit( ) {
		# We're expecting a JSON payload in the form:
		#
		# payload="{
		#		source:"gitblit",
		#		before:$headIdBeforeReceive,
		#		after:$headIdAfterReceive,
		#		ref:$commitBranch,
		#		repo:{
		#			name:$repoName,
		#			url:$repoUrl
		#		},
		#		commits:[
		#			{
		#				author:{
		#					email:$authorEmail,
		#					name:$authorName
		#				},
		#				committer:{
		#					email:$committerEmail,
		#					name:$committerName
		#				},
		#				date:$dateInSeconds,
		#				added:[$addedFilePaths],
		#				modified:[$modifiedFilePaths],
		#				removed:[$deletedFilePaths],
		#				id:$commitId,
		#               parents:[$parentIds],
		#				url:$commitUrl,
		#				message:$commitMessage
		#			}
		#		]
		#	}"
		#
		# So first check to make sure we have a payload and a source of 'gitblit'
		 
		$f_payload = gpc_get_string( 'payload', null );
		if ( is_null( $f_payload ) ) {
			return;
		}

		$pos = stripos( $f_payload, 'gitblit' );
		if ( false === stripos( $f_payload, 'gitblit' ) ) {
			return;
		}

		# decode the json object into a normal associative array
		$t_data = json_decode( $f_payload );
		
		$t_reponame = $t_data->repo->name;
				
		$t_repo_table = plugin_table( 'repository', 'Source' );

		$t_query = "SELECT * FROM $t_repo_table WHERE info LIKE " . db_param();
		$t_result = db_query_bound( $t_query, array( '%' . $t_reponame . '%' ) );

		if ( db_num_rows( $t_result ) < 1 ) {
			echo "didn't find any repository for " . $t_reponame . ".\n";
			return;
		}

		while ( $t_row = db_fetch_array( $t_result ) ) {
			$t_repo = new SourceRepo( $t_row['type'], $t_row['name'], $t_row['url'], $t_row['info'] );
			$t_repo->id = $t_row['id'];

			if ( $t_repo->info['gitblit_project'] == $t_reponame ) {
				return array( 'repo' => $t_repo, 'data' => $t_data );
			}
		}

		return;	
	}

	public function commit( $p_repo, $p_data ) {
		$t_commits = array();

		foreach( $p_data->commits as $t_commit ) {
			$t_commits[] = $t_commit->id;
		}

		$t_refData = split('/',$p_data->ref);
		$t_branch = $t_refData[2];

		return $this->import_commits( $p_repo, $t_commits, $t_branch, $p_data );
	}

	public function import_full( $p_repo ) {
		echo '<pre>';

		$t_branch = $p_repo->info['master_branch'];
		if ( is_blank( $t_branch ) ) {
			$t_branch = 'master';
		}

		if ($t_branch != '*')
		{
			$t_branches = array_map( 'trim', explode( ',', $t_branch ) );
		}
		else
		{
			// we have no way of getting branch names if not provided
			echo "You must specify one or more master_branch values in the repo configuration.\n";
			die("import full failed.");
		}
		$t_changesets = array();

		$t_changeset_table = plugin_table( 'changeset', 'Source' );

		foreach( $t_branches as $t_branch ) {
			$t_query = "SELECT parent FROM $t_changeset_table
				WHERE repo_id=" . db_param() . ' AND branch=' . db_param() .
				'ORDER BY timestamp ASC';
			$t_result = db_query_bound( $t_query, array( $p_repo->id, $t_branch ), 1 );

			$t_commits = array( $t_branch );

			if ( db_num_rows( $t_result ) > 0 ) {
				$t_parent = db_result( $t_result );
				echo "Oldest '$t_branch' branch parent: '$t_parent'\n";

				if ( !empty( $t_parent ) ) {
					$t_commits[] = $t_parent;
				}
			}

			$t_changesets = array_merge( $t_changesets, $this->import_commits( $p_repo, $t_commits, $t_branch ) );
		}

		echo '</pre>';

		return $t_changesets;
	}

	public function import_latest( $p_repo ) {
		return $this->import_full( $p_repo );
	}

	public function import_commits( $p_repo, $p_commit_ids, $p_branch='', $p_data=null ) {
		static $s_parents = array();
		static $s_counter = 0;

		if ( is_array( $p_commit_ids ) ) {
			$s_parents = array_merge( $s_parents, $p_commit_ids );
		} else {
			$s_parents[] = $p_commit_ids;
		}

		$t_changesets = array();

		while( count( $s_parents ) > 0 && $s_counter < 200 ) {
			$t_commit_id = array_shift( $s_parents );

// 			echo "Verifying $t_commit_id ... ";
			if ($p_data == null) {
				# what to do?  call out to GitBlit and try to scrape the info from the RSS feed?
				# fail for now...
				echo "failed to import commits.\n";
				return array();
			} else {
				// get the commit data for the commit we're working with - the commit ids are unique
				foreach (((array)$p_data->commits) as $t_commit) {
					if ($t_commit->id == $t_commit_id) {
						$t_commit_data = $t_commit;
						break;
					}
				}
				
				if ( !property_exists( $t_commit_data, 'id' ) ) {
					echo "failed ($t_commit_data->message).\n";
					continue;
				}
	
				list( $t_changeset, $t_commit_parents ) = $this->json_commit_changeset( $p_repo, $t_commit_data, $p_branch );
				if ( $t_changeset ) {
					$t_changesets[] = $t_changeset;
				}

				$s_parents = array_merge( $s_parents, $t_commit_parents );
			}
		}

		$s_counter = 0;
		return $t_changesets;
	}

	private function json_commit_changeset( $p_repo, $p_json, $p_branch='' ) {

		if ( !SourceChangeset::exists( $p_repo->id, $p_json->id ) ) {
			$t_parents = array();

			$t_changeset = new SourceChangeset(
				$p_repo->id,
				$p_json->id,
				$p_branch,
				date( 'Y-m-d H:i:s', $p_json->date),
				$p_json->author->name,
				$p_json->message
			);

			if ( count( $p_json->parents ) > 0) {
				$t_parent = $p_json->parents[0];
				$t_changeset->parent = $t_parent;
			}
			
			$t_changeset->author_email = $p_json->author->email;
			$t_changeset->committer = $p_json->committer->name;
			$t_changeset->committer_email = $p_json->committer->email;

			if ( isset( $p_json->added ) ) {
				foreach ( $p_json->added as $t_file ) {
					$t_changeset->files[] = new SourceFile( 0, '', $t_file, 'add' );
				}
			}
			if ( isset( $p_json->modified ) ) {
				foreach ( $p_json->modified as $t_file ) {
					$t_changeset->files[] = new SourceFile( 0, '', $t_file, 'mod' );
				}
			}
			if ( isset( $p_json->removed ) ) {
				foreach ( $p_json->removed as $t_file ) {
					$t_changeset->files[] = new SourceFile( 0, '', $t_file, 'rm' );
				}
			}

			$t_changeset->save();

// 			echo "saved.\n";
			return array( $t_changeset, $t_parents );
		} else {
			echo "already exists.\n";
			return array( null, array() );
		}
	}
}
