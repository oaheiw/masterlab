<?php
namespace main\app\ctrl\project;

use main\app\classes\LogOperatingLogic;
use main\app\classes\ProjectListCountLogic;
use main\app\classes\ProjectLogic;
use main\app\classes\PermissionLogic;
use main\app\classes\UserAuth;
use main\app\classes\UserLogic;
use main\app\ctrl\BaseUserCtrl;
use main\app\ctrl\Org;
use main\app\model\OrgModel;
use main\app\model\project\ProjectIssueTypeSchemeDataModel;
use main\app\model\project\ProjectMainExtraModel;
use main\app\model\project\ProjectModel;
use main\app\model\project\ProjectVersionModel;
use main\app\model\project\ProjectModuleModel;
use main\app\model\user\UserModel;

class Setting extends BaseUserCtrl
{
    public function __construct()
    {
        parent::__construct();
        parent::addGVar('top_menu_active', 'project');
    }

    /**
     * 修改项目信息
     * @throws \Exception
     */
    public function saveSettingsProfile()
    {
        if (isPost()) {
            $params = $_POST['params'];
            $uid = $this->getCurrentUid();
            $projectModel = new ProjectModel($uid);
            $preData = $projectModel->getRowById($_GET[ProjectLogic::PROJECT_GET_PARAM_ID]);
            $projectIssueTypeSchemeDataModel = new ProjectIssueTypeSchemeDataModel();

            if (isset($params['type']) && empty(trimStr($params['type']))) {
                $this->ajaxFailed('param_error:type_is_null');
            }

            $params['type'] = intval($params['type']);

            if (!isset($params['lead']) || empty($params['lead'])) {
                $params['lead'] = $uid;
            }

            $info = [];
            //$info['org_id'] = $params['org_id'];  无需修改组织
            $info['lead'] = $params['lead'];
            $info['description'] = $params['description'];
            $info['type'] = $params['type'];
            $info['category'] = 0;
            $info['url'] = $params['url'];
            $info['avatar'] = !empty($params['avatar_relate_path']) ? $params['avatar_relate_path'] : '';
            //$info['detail'] = $params['detail'];

            $projectModel->db->beginTransaction();

            //$orgModel = new OrgModel();
            //$orgInfo = $orgModel->getById($params['org_id']);
            //$info['org_path'] = $orgInfo['path'];

            $ret1 = $projectModel->update($info, array('id' => $_GET[ProjectLogic::PROJECT_GET_PARAM_ID]));
            $projectMainExtra = new ProjectMainExtraModel();
            if ($projectMainExtra->getByProjectId($_GET[ProjectLogic::PROJECT_GET_PARAM_ID])) {
                $ret3 = $projectMainExtra->updateByProjectId(array('detail' => $params['detail']), $_GET[ProjectLogic::PROJECT_GET_PARAM_ID]);
            } else {
                $ret3 = $projectMainExtra->insert(array('project_id' => $_GET[ProjectLogic::PROJECT_GET_PARAM_ID], 'detail' => $params['detail']));
            }

            $schemeId = ProjectLogic::getIssueTypeSchemeId($params['type']);
            $retSchemeId = $projectIssueTypeSchemeDataModel->getSchemeId($_GET[ProjectLogic::PROJECT_GET_PARAM_ID]);
            if ($retSchemeId) {
                $ret2 = $projectIssueTypeSchemeDataModel->update(array('issue_type_scheme_id' => $schemeId), array('project_id' => $_GET[ProjectLogic::PROJECT_GET_PARAM_ID]));
            } else {
                $ret2 = $projectIssueTypeSchemeDataModel->insert(array('issue_type_scheme_id' => $schemeId, 'project_id' => $_GET[ProjectLogic::PROJECT_GET_PARAM_ID]));
            }


            if (!isset($info['type'])
                || !is_numeric($info['type'])
                || !in_array($info['type'], ProjectLogic::$type_all)
            ) {
                $this->ajaxFailed('参数错误', '项目类型错误');
            }

            $retUpdateProjectListCount = true;
            $projectListCountLogic = new ProjectListCountLogic();
            foreach (ProjectLogic::$type_all as $typeId) {
                if (!$projectListCountLogic->resetProjectTypeCount($typeId)) {
                    $retUpdateProjectListCount = false;
                    break;
                }
            }

            if ($ret1[0] && $ret2[0] && $ret3[0] && $retUpdateProjectListCount) {
                $projectModel->db->commit();

                //写入操作日志
                $logData = [];
                $logData['user_name'] = $this->auth->getUser()['username'];
                $logData['real_name'] = $this->auth->getUser()['display_name'];
                $logData['obj_id'] = 0;
                $logData['module'] = LogOperatingLogic::MODULE_NAME_PROJECT;
                $logData['page'] = $_SERVER['REQUEST_URI'];
                $logData['action'] = LogOperatingLogic::ACT_EDIT;
                $logData['remark'] = '修改项目信息';
                $logData['pre_data'] = $preData;
                $logData['cur_data'] = $info;
                LogOperatingLogic::add($uid, $_GET[ProjectLogic::PROJECT_GET_PARAM_ID], $logData);

                $this->ajaxSuccess("success");
            } else {
                $projectModel->db->rollBack();
                $this->ajaxFailed('错误', '更新数据失败');
            }
        } else {
            $this->ajaxFailed('错误', '请求方式ERR');
        }
    }

    public function updateProjectKey()
    {
        if (isPost()) {
            $params = $_POST['params'];
            $uid = $this->getCurrentUid();
            $projectModel = new ProjectModel($uid);

            if (!isset($params['key']) || !isset($params['new_key'])) {
                $this->ajaxFailed('param_error:need key name');
            }

            $params['new_key'] = trim($params['new_key']);
            if ($params['key'] == $params['new_key']) {
                $this->ajaxFailed('param_error:key repetition');
            }

            $isNotKey = $projectModel->checkIdKeyExist($_GET[ProjectLogic::PROJECT_GET_PARAM_ID], $params['new_key']);
            if ($isNotKey) {
                $this->ajaxFailed('param_error:KEY Exist.');
            }

            $info = [];
            $info['key'] = $params['new_key'];
            $ret = $projectModel->update($info, array("id" => $_GET[ProjectLogic::PROJECT_GET_PARAM_ID]));

            if ($ret[0]) {
                $this->ajaxSuccess("success");
            } else {
                $this->ajaxFailed('错误', '更新数据失败,详情:' . $ret[1]);
            }
        } else {
            $this->ajaxFailed('错误', '请求方式ERR');
        }
    }

    public function update($project_id, $name, $key, $type, $url = '', $category = '', $avatar = '', $description = '')
    {
        // @todo 判断权限:全局权限和项目角色
        $uid = $this->getCurrentUid();
        $projectModel = new ProjectModel($uid);
        $this->param_valid($projectModel, $name, $key, $type);


        $project_id = intval($project_id);

        $info = [];
        if (isset($_REQUEST['name'])) {
            $name = trimStr($_REQUEST['name']);
            if ($projectModel->checkIdNameExist($project_id, $name)) {
                $this->ajaxFailed('param_error:name_exist');
            }
            $info['name'] = trimStr($_REQUEST['name']);
        }
        if (isset($_REQUEST['key'])) {
            $key = trimStr($_REQUEST['key']);
            if ($projectModel->checkIdKeyExist($project_id, $key)) {
                $this->ajaxFailed('param_error:key_exist');
            }
            $info['key']   =  trimStr($_REQUEST['key']);
        }
        if (isset($_REQUEST['type'])) {
            $info['type'] = intval($_REQUEST['type']);
        }
        if (isset($_REQUEST['lead'])) {
            $info['lead'] = intval($_REQUEST['lead']);
        }
        if (isset($_REQUEST['description'])) {
            $info['description'] = $_REQUEST['description'];
        }
        if (isset($_REQUEST['category'])) {
            $info['category'] = (int) $_REQUEST['category'];
        }
        if (isset($_REQUEST['url'])) {
            $info['url']   =  $_REQUEST['url'];
        }
        if (isset($_REQUEST['avatar'])) {
            $info['avatar']   =  $_REQUEST['avatar'];
        }
        if (empty($info)) {
            $this->ajaxFailed('param_error:data_is_empty');
        }
        $project = $projectModel->getRowById($project_id);
        $ret= $projectModel->updateById($project_id, $info);
        if ($ret[0]) {
            if ($project['key'] != $key) {
                // @todo update issue key
            }
            $this->ajaxSuccess('add_success');
        } else {
            $this->ajaxFailed('add_failed');
        }
    }


    public function delete($project_id)
    {
        if (empty($project_id)) {
            $this->ajaxFailed('no_project_id');
        }
        // @todo 判断权限

        $uid = $this->getCurrentUid();
        $project_id = intval($project_id);
        $projectModel = new ProjectModel($uid);
        $ret = $projectModel->deleteById($project_id);
        if (!$ret) {
            $this->ajaxFailed('delete_failed');
        } else {
            // @todo 删除事项


            // @todo 删除版本
            $projectVersionModel = new ProjectVersionModel($uid);
            $projectVersionModel->deleteByProject($project_id);

            // @todo 删除模块
            $projectModuleModel = new ProjectModuleModel($uid);
            $projectModuleModel->deleteByProject($project_id);

            $this->ajaxSuccess('success');
        }
    }


}
